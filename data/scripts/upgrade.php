<?php declare(strict_types=1);

namespace Analytics;

use Common\Stdlib\PsrMessage;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.96')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.96'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

$hasError = false;

if (PHP_VERSION_ID < 80100) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s requires PHP %2$s or later.'), // @translate
        'Analytics', '8.1'
    );
    $messenger->addError($message);
    $hasError = true;
}

if ($hasError) {
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if (version_compare((string) $oldVersion, '3.4.12', '<')) {
    // Detect tracked types from existing .htaccess rule and save as setting.
    $htaccessPath = OMEKA_PATH . '/.htaccess';
    $htaccess = @file_get_contents($htaccessPath);
    $detectedTypes = [];
    $knownTypes = ['original', 'large', 'medium', 'square'];
    if ($htaccess !== false) {
        $marker = '# Module Analytics: count downloads.';
        if (strpos($htaccess, $marker) !== false
            && preg_match('/' . preg_quote($marker, '/') . '\s*\n(?:\s*#[^\n]*\n)*\s*RewriteRule\s+"\^files\/\(([^)]+)\)\//', $htaccess, $matches)
        ) {
            $detectedTypes = explode('|', $matches[1]);
        } else {
            if (preg_match_all('/^\s*RewriteRule\s+.*files\/\(([^)]+)\).*\/download\/files\//m', $htaccess, $matches)) {
                foreach ($matches[1] as $group) {
                    $detectedTypes = array_merge($detectedTypes, explode('|', $group));
                }
            }
            if (preg_match_all('/^\s*RewriteRule\s+["\^]*files\/(' . implode('|', $knownTypes) . ')\/.*\/download\/files\//m', $htaccess, $matches)) {
                $detectedTypes = array_merge($detectedTypes, $matches[1]);
            }
            $detectedTypes = array_values(array_unique(array_intersect($detectedTypes, $knownTypes)));
        }
    }
    if (empty($detectedTypes)) {
        $detectedTypes = ['original'];
    }
    $settings->set('analytics_htaccess_types', $detectedTypes);
    $settings->set('analytics_htaccess_custom_types', '');
    $message = new PsrMessage(
        'A new setting allows to manage the .htaccess download tracking rule from the settings. Detected file types: {types}.', // @translate
        ['types' => implode(', ', $detectedTypes)]
    );
    $messenger->addNotice($message);
}

if (version_compare($oldVersion, '3.4.14', '<')) {
    $siteSettings = $services->get('Omeka\Settings\Site');
    $siteIds = $api->search('sites', [], ['returnScalar' => 'id'])->getContent();
    foreach ($siteIds as $siteId) {
        $siteSettings->setTargetId($siteId);
        $siteSettings->set('analytics_placement', ['after/items', 'after/media', 'after/item_sets']);
    }
}

if (version_compare($oldVersion, '3.4.15', '<')) {
    // The .htaccess download rule now coexists with the module Access rule:
    // types covered by Access are skipped (logged via dispatch), remaining
    // types still get an Analytics rewrite rule. Re-save the settings to
    // refresh the .htaccess in case the previous version stripped the rule.
    $messenger->addSuccess(new PsrMessage(
        'When the server sits behind a reverse proxy, add the IPs/CIDR of the proxy to the trusted reverse proxies, in the security section of the main settings, to track real client IPs through X-Forwarded-For / X-Real-IP.' // @translate
    ));
    $messenger->addWarning(new PsrMessage(
        'It is recommended to check config if the server is behind a proxy.' // @translate
    ));
    $messenger->addSuccess(new PsrMessage(
        'The .htaccess download tracking rule now coexists with the module Access rule. Types handled by Access are tracked through the Access dispatch; remaining types keep their own Analytics rule. Open the module settings and save once to refresh the .htaccess if needed.' // @translate
    ));
}

// The trusted proxies are managed in the main settings (module Common): merge
// the list of the module into it.
$moduleTrustedProxies = $settings->get('analytics_trusted_proxies');
if ($moduleTrustedProxies !== null) {
    $trustedProxies = array_values(array_unique(array_filter(array_merge(
        (array) ($settings->get('trusted_proxies') ?: []),
        is_array($moduleTrustedProxies) ? $moduleTrustedProxies : preg_split('/[\s,;]+/', (string) $moduleTrustedProxies)
    ))));
    $settings->set('trusted_proxies', $trustedProxies);
    $settings->delete('analytics_trusted_proxies');
    if ($trustedProxies) {
        $messenger->addWarning(new \Omeka\Stdlib\Message(
            'The trusted reverse proxies are now managed in the security section of the main settings and apply to all modules, including the ones that grant rights or lock out by ip. Check that each one is a reverse proxy of the server: %s.', // @translate
            implode(', ', $trustedProxies)
        ));
    }
}

if (version_compare($oldVersion, '3.4.16', '<')) {
    // Keep the values set during a previous partial upgrade.
    if ($settings->get('analytics_deduplication_minutes') === null) {
        $settings->set('analytics_deduplication_minutes', \Analytics\Api\Adapter\HitAdapter::DEDUPLICATION_MINUTES);
    }
    if ($settings->get('analytics_segments') === null) {
        $settings->set('analytics_segments', false);
    }

    // A plain hash of an ip is reversible (the whole ipv4 space can be hashed
    // in seconds), so the hashed ips are now salted: anonymize the past ones.
    // The salt is created with the first salted hash, so the new hashes are
    // never anonymized when the upgrade is replayed.
    if ($settings->get('analytics_privacy') === 'hashed' && !$settings->get('analytics_ip_salt')) {
        $anonymized = $connection->executeStatement(
            'UPDATE `hit` SET `ip` = "::" WHERE `ip` REGEXP "^[0-9a-f]{32}$"'
        );
        if ($anonymized) {
            $messenger->addWarning(new PsrMessage(
                'The hashed ips were reversible, because the whole ipv4 space can be hashed in seconds: {count} past hits are anonymized now. The new hits use a hash with a secret salt of the site.', // @translate
                ['count' => $anonymized]
            ));
        }
    }

    // In partial modes, the ipv6 were stored whole: anonymize them, but not
    // the new partial ones, that end with "---".
    if (in_array($settings->get('analytics_privacy'), ['partial_1', 'partial_2', 'partial_3'])) {
        $anonymized = $connection->executeStatement(
            'UPDATE `hit` SET `ip` = "::" WHERE `ip` LIKE "%:%" AND `ip` != "::" AND `ip` NOT LIKE "%---"'
        );
        if ($anonymized) {
            $messenger->addWarning(new PsrMessage(
                'In partial privacy modes, the ipv6 of the visitors were stored whole: {count} past hits are anonymized now. The new hits keep only the first groups of the ipv6.', // @translate
                ['count' => $anonymized]
            ));
        }
    }

    (new \Analytics\Stdlib\BotCounter($connection))->createTable();

    $sql = <<<'SQL'
        CREATE TABLE IF NOT EXISTS `analytics_segment` (
            `id` INT AUTO_INCREMENT NOT NULL,
            `entity_name` VARCHAR(190) DEFAULT '' NOT NULL,
            `entity_id` INT DEFAULT 0 NOT NULL,
            `type` VARCHAR(8) NOT NULL,
            `bucket` SMALLINT DEFAULT 0 NOT NULL,
            `period` INT DEFAULT 0 NOT NULL,
            `hits` INT DEFAULT 0 NOT NULL,
            UNIQUE INDEX UNIQ_ANALYTICS_SEGMENT (`entity_name`, `entity_id`, `type`, `bucket`, `period`),
            INDEX IDX_ANALYTICS_SEGMENT_ENTITY (`entity_id`, `entity_name`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL;
    $connection->executeStatement($sql);

    $message = new PsrMessage(
        'The hits of files are now deduplicated: the probe, the ranges of an audio or video file, the preload of a player and the replays by the same visitor (same ip, user agent and language) within {minutes} minutes count as a single hit. Head requests and requests of regions of images (tiles) are no longer counted: the view of an image is its info.json. A new option allows to aggregate the requested zones of the images and the played parts of the audio and video files, as a heat map.', // @translate
        ['minutes' => \Analytics\Api\Adapter\HitAdapter::DEDUPLICATION_MINUTES]
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'The past statistics of files and images remain overcounted until you run the task "Analytics: Clean hits", available as a checkbox in the main settings and in the module Easy Admin: it deduplicates the past hits of files, removes the hits of the regions of the images, and rebuilds the statistics, so the counts of files and images will decrease. The removed hits are archived in the table "hit_duplicate", to check then to empty, or to restore.' // @translate
    );
    $messenger->addWarning($message);

    // The archive of the removed duplicate hits is read only by id.
    if ($connection->executeQuery('SHOW TABLES LIKE "hit_duplicate"')->fetchOne()) {
        $indexes = $connection->executeQuery('SHOW INDEX FROM `hit_duplicate` WHERE `Key_name` <> "PRIMARY"')->fetchAllAssociative();
        foreach (array_unique(array_column($indexes, 'Key_name')) as $index) {
            $connection->executeStatement("ALTER TABLE `hit_duplicate` DROP INDEX `$index`");
        }
    }

    // The indexes of the hits are updated in a background job, because the
    // table may be huge. The job is idempotent.
    $hasNewIndex = (bool) $connection->executeQuery('SHOW INDEX FROM `hit` WHERE `Key_name` = "IDX_5AD22641F47645AEB23DB7B8"')->fetchOne();
    $hasOldIndex = (bool) $connection->executeQuery('SHOW INDEX FROM `hit` WHERE `Non_unique` = 1 AND `Column_name` IN ("ip", "referrer", "user_agent", "accept_language")')->fetchOne();
    if (!$hasNewIndex || $hasOldIndex) {
        // Mark the module as upgraded and active, so the php-cli process of
        // the job can bootstrap it. So this block must remain the last one.
        require_once dirname(__DIR__, 2) . '/src/Job/UpdateDatabaseIndexes.php';

        $moduleId = 'Analytics';
        $wasActive = (bool) $connection->executeQuery(
            'SELECT `is_active` FROM `module` WHERE `id` = ?',
            [$moduleId]
        )->fetchOne();

        $connection->executeStatement(
            'UPDATE `module` SET `version` = ?, `is_active` = 1 WHERE `id` = ?',
            [$newVersion, $moduleId]
        );

        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\Analytics\Job\UpdateDatabaseIndexes::class);

        sleep(5);

        $status = $connection->executeQuery(
            'SELECT `status` FROM `job` WHERE `id` = ?',
            [$job->getId()]
        )->fetchOne();
        if ($status === \Omeka\Entity\Job::STATUS_STARTING) {
            $messenger->addWarning(new PsrMessage(
                'The job #{job_id} is still starting. It may need to be relaunched manually with the task "Analytics: Update indexes" of the module Easy Admin.', // @translate
                ['job_id' => $job->getId()]
            ));
        }

        if (!$wasActive) {
            $connection->executeStatement(
                'UPDATE `module` SET `is_active` = 0 WHERE `id` = ?',
                [$moduleId]
            );
        }

        $messenger->addWarning(new PsrMessage(
            'The indexes of the table of hits are being updated in a background job (#{job_id}): a composite index on the url and the date is added, then the useless indexes (ip, referrer, user agent, accepted languages) are removed, so the recording of the hits is quicker. It may take a long time on a big table, but the site remains available.', // @translate
            ['job_id' => $job->getId()]
        ));
    }
}
