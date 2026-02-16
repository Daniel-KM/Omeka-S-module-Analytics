<?php declare(strict_types=1);

namespace Analytics;

if (!class_exists('Common\TraitModule', false)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceRepresentation;
use Omeka\Module\AbstractModule;

/**
 * Analytics
 *
 * Logger that counts views of pages and resources.
 *
 * @copyright Daniel Berthereau, 2014-2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    use TraitModule;

    public function init(ModuleManager $moduleManager): void
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    public function install(ServiceLocatorInterface $services): void
    {
        $this->setServiceLocator($services);

        $this->preInstall();

        // The module Analytics was split from the module Statistics, so the
        // tables "hit" and "stat" may already exist with data. In that case,
        // check the structure and reuse them instead of failing.
        $sqlFile = $this->modulePath() . '/data/install/schema.sql';
        if ($this->checkExistingTablesFromStatistics()) {
            // Tables exist with the right structure: skip creation.
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            $message = new PsrMessage(
                'The tables from the module Statistics were found and will be reused by the module Analytics.' // @translate
            );
            $messenger->addNotice($message);
        } else {
            // Standard install: check and create tables.
            if (!$this->checkNewTablesFromFile($sqlFile)) {
                $message = new PsrMessage(
                    'This module cannot install its tables, because they exist already. Try to remove them first.' // @translate
                );
                $messenger = $services->get('ControllerPluginManager')->get('messenger');
                $messenger->addError($message);
                throw new \Omeka\Module\Exception\ModuleCannotInstallException(
                    (string) $services->get('ControllerPluginManager')->get('translate')('Missing requirement. Unable to install.') // @translate
                );
            }
            $this->execSqlFromFile($sqlFile);
        }

        $this
            ->installAllResources()
            ->manageConfig('install')
            ->manageMainSettings('install')
            ->manageSiteSettings('install')
            ->manageUserSettings('install')
            ->postInstall();
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.69')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.69'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    /**
     * Check if the tables "hit" and "stat" already exist with the expected
     * columns, meaning they were created by the module Statistics before the
     * split.
     */
    protected function checkExistingTablesFromStatistics(): bool
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');

        $tables = $connection->executeQuery('SHOW TABLES;')->fetchFirstColumn();
        if (!in_array('hit', $tables) || !in_array('stat', $tables)) {
            return false;
        }

        // Check that the tables contain data (empty tables are handled by the
        // standard install process).
        $hasHitData = $connection->executeQuery('SELECT 1 FROM `hit` LIMIT 1;')->fetchOne();
        $hasStatData = $connection->executeQuery('SELECT 1 FROM `stat` LIMIT 1;')->fetchOne();
        if ($hasHitData === false && $hasStatData === false) {
            return false;
        }

        // Verify the expected columns exist.
        $hitColumns = $connection->executeQuery('SHOW COLUMNS FROM `hit`;')->fetchAllAssociativeIndexed();
        $expectedHitColumns = ['id', 'url', 'entity_id', 'entity_name', 'site_id', 'user_id', 'ip', 'query', 'referrer', 'user_agent', 'accept_language', 'created'];
        foreach ($expectedHitColumns as $col) {
            if (!isset($hitColumns[$col])) {
                return false;
            }
        }

        $statColumns = $connection->executeQuery('SHOW COLUMNS FROM `stat`;')->fetchAllAssociativeIndexed();
        $expectedStatColumns = ['id', 'type', 'url', 'entity_id', 'entity_name', 'hits', 'hits_anonymous', 'hits_identified', 'created', 'modified'];
        foreach ($expectedStatColumns as $col) {
            if (!isset($statColumns[$col])) {
                return false;
            }
        }

        return true;
    }

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        // Set default htaccess types and try to write the rule.
        $settings->set('analytics_htaccess_types', ['original']);
        $this->manageHtaccess(['original']);
    }

    protected function preUninstall(): void
    {
        $htaccessPath = OMEKA_PATH . '/.htaccess';
        $htaccess = @file_get_contents($htaccessPath);
        if ($htaccess === false) {
            return;
        }

        $marker = '# Module Analytics: count downloads.';
        if (strpos($htaccess, $marker) === false) {
            return;
        }

        if (!is_writable($htaccessPath)) {
            $services = $this->getServiceLocator();
            $logger = $services->get('Omeka\Logger');
            $logger->warn('Analytics module: the .htaccess is not writable; the rewrite rule was not removed during uninstall.'); // @translate
            return;
        }

        $htaccess = preg_replace('/' . preg_quote($marker, '/') . '\s*\n(?:\s*#[^\n]*\n)*\s*RewriteRule\s+"[^"]*"\s+"[^"]*"\s+\[[^\]]*\]\s*\n?/', '', $htaccess);
        file_put_contents($htaccessPath, $htaccess);
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /** @var \Omeka\Permissions\Acl $acl */
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        $acl
            ->allow(
                null,
                [
                    \Analytics\Entity\Hit::class,
                    \Analytics\Entity\Stat::class,
                ],
                ['read', 'create', 'search']
            )
            ->allow(
                null,
                [
                    \Analytics\Api\Adapter\HitAdapter::class,
                    \Analytics\Api\Adapter\StatAdapter::class,
                ],
                ['read', 'create', 'search']
            )
            ->allow(
                null,
                ['Analytics\Controller\Download']
            )
        ;

        $settings = $services->get('Omeka\Settings');
        if ($settings->get('analytics_public_allow_summary')) {
            $acl
                ->allow(
                    null,
                    ['Analytics\Controller\Analytics'],
                    ['index']
                );
        }
        // Browse implies Summary.
        if ($settings->get('analytics_public_allow_browse')) {
            $acl
                ->allow(
                    null,
                    ['Analytics\Controller\Analytics']
                );
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Events for the public front-end.
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.after',
            [$this, 'displayPublic']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\ItemSet',
            'view.show.after',
            [$this, 'displayPublic']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Media',
            'view.show.after',
            [$this, 'displayPublic']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Page',
            'view.show.after',
            [$this, 'displayPublic']
        );

        // Events for the admin front-end.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.details',
            [$this, 'viewDetails']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.details',
            [$this, 'viewDetails']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.details',
            [$this, 'viewDetails']
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Index',
            'view.browse.after',
            [$this, 'filterAdminDashboardPanels']
        );

        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );

        // Add a job for EasyAdmin.
        $sharedEventManager->attach(
            \EasyAdmin\Form\CheckAndFixForm::class,
            'form.add_elements',
            [$this, 'handleEasyAdminJobsForm']
        );
        $sharedEventManager->attach(
            \EasyAdmin\Controller\Admin\CheckAndFixController::class,
            'easyadmin.job',
            [$this, 'handleEasyAdminJobs']
        );

        $sharedEventManager->attach(
            \BulkImport\Processor\EprintsProcessor::class,
            'bulk.import.after',
            [$this, 'handleBulkImportAfter']
        );
    }

    public function displayPublic(Event $event): void
    {
        $view = $event->getTarget();
        $resource = $view->vars()->offsetGet('resource');
        echo $view->analytics()->textResource($resource);
    }

    public function viewDetails(Event $event): void
    {
        $view = $event->getTarget();
        $representation = $event->getParam('entity');
        $statTitle = $view->translate('Analytics'); // @translate
        $statText = $this->resultResource($view, $representation);
        $html = <<<HTML
            <div class="meta-group">
                <h4>$statTitle</h4>
                $statText
            </div>
            HTML . "\n";
        echo $html;
    }

    protected function resultResource(PhpRenderer $view, AbstractResourceRepresentation $resource)
    {
        /** @var \Analytics\View\Helper\Analytics $analytics */
        $plugins = $view->getHelperPluginManager();
        $analytics = $plugins->get('analytics');
        $translate = $plugins->get('translate');

        $html = '<ul class="value">';
        $html .= '<li>';
        $html .= sprintf(
            $translate('Views: %d (anonymous: %d / users: %d)'), // @translate
            $analytics->totalResource($resource),
            $analytics->totalResource($resource, null, 'anonymous'),
            $analytics->totalResource($resource, null, 'identified')
        );
        $html .= '</li>';
        $html .= '<li>';
        $html .= sprintf(
            $translate('Position: %d (anonymous: %d / users: %d)'), // @translate
            $analytics->positionResource($resource),
            $analytics->positionResource($resource, null, 'anonymous'),
            $analytics->positionResource($resource, null, 'identified')
        );
        $html .= '</li>';
        $html .= '</ul>';
        return $html;
    }

    public function filterAdminDashboardPanels(Event $event): void
    {
        /**
         * @var \Analytics\View\Helper\Analytics $analytics
         * @var \Omeka\Settings\Settings $settings
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        if ($settings->get('analytics_disable_dashboard')) {
            return;
        }

        $view = $event->getTarget();
        $plugins = $view->getHelperPluginManager();
        $userIsAllowed = $plugins->get('userIsAllowed');

        $userIsAllowedSummary = $userIsAllowed('Analytics\Controller\Analytics', 'index');
        $userIsAllowedBrowse = $userIsAllowed('Analytics\Controller\Analytics', 'browse');
        if (!$userIsAllowedSummary && !$userIsAllowedBrowse) {
            return;
        }

        $url = $plugins->get('url');
        $escape = $plugins->get('escapeHtml');
        $settings = $services->get('Omeka\Settings');
        $translate = $plugins->get('translate');
        $analytics = $plugins->get('analytics');
        $escapeAttr = $plugins->get('escapeHtmlAttr');

        $userStatus = $settings->get('analytics_default_user_status_admin');
        $totalHits = $analytics->totalHits([], $userStatus);

        $statsTitle = $translate('Analytics'); // @translate
        $html = <<<HTML
            <div id="stats" class="panel">
                <h2>$statsTitle</h2>
            HTML . "\n";

        if ($userIsAllowedSummary) {
            $statsSummaryUrl = $url('admin/analytics', [], true);
            $statsSummaryText = sprintf($translate('Total Hits: %d'), $totalHits); // @translate
            $lastTexts = [
                30 => $translate('Last 30 days'),
                7 => $translate('Last 7 days'),
                1 => $translate('Last 24 hours'),
            ];
            $lastTotals = [
                30 => $analytics->totalHits(['since' => date('Y-m-d', strtotime('-30 days'))], $userStatus),
                7 => $analytics->totalHits(['since' => date('Y-m-d', strtotime('-7 days')), 'user_status' => $userStatus]),
                1 => $analytics->totalHits(['since' => date('Y-m-d', strtotime('-1 days')), 'user_status' => $userStatus]),
            ];
            $html .= <<<HTML
                <h4><a href="$statsSummaryUrl">$statsSummaryText</a></h4>
                <ul>
                    <li>$lastTexts[30] : $lastTotals[30]</li>
                    <li>$lastTexts[7] : $lastTotals[7]</li>
                    <li>$lastTexts[1] : $lastTotals[1]</li>
                </ul>
            HTML . "\n";
        }

        if ($userIsAllowedBrowse) {
            $statsBrowseUrl = $url('admin/analytics/default', ['action' => 'by-page'], true);
            $statsBrowseText = $translate('Most viewed public pages'); // @translate
            $html .= '<h4><a href="' . $statsBrowseUrl . '">' . $statsBrowseText . '</a></h4>';
            /** @var \Analytics\Api\Representation\StatRepresentation[] $stats */
            $stats = $analytics->mostViewedPages(null, $userStatus, 1, 5);
            if (empty($stats)) {
                $html .= '<p>' . $translate('None') . '</p>';
            } else {
                $html .= '<ol>';
                foreach ($stats as $stat) {
                    $html .= '<li>';
                    $html .= sprintf(
                        $translate('%s (%d views)'),
                        '<a href="' . $escapeAttr($stat->hitUrl(true)) . '">' . $escape($stat->hitUrl()) . '</a>',
                        $stat->totalHits($userStatus)
                    );
                    $html .= '</li>';
                }
                $html .= '</ol>';
            }

            $statsBrowseUrl = $url('admin/analytics/default', ['action' => 'by-resource'], true);
            $statsBrowseText = $translate('Most viewed public item'); // @translate
            $html .= '<h4><a href="' . $statsBrowseUrl . '">' . $statsBrowseText . '</a></h4>';
            $stats = $analytics->mostViewedResources('items', $userStatus, 1, 5);
            if (empty($stats)) {
                $html .= '<p>' . $translate('None') . '</p>';
            } else {
                $stat = reset($stats);
                $html .= '<ul>';
                $html .= sprintf($translate('%s (%d views)'), // @translate
                    $stat->linkEntity(),
                    $stat->totalHits($userStatus)
                );
                $html .= '</ul>';
            }

            $statsBrowseUrl = $url('admin/analytics/default', ['action' => 'by-resource'], true);
            $statsBrowseText = $translate('Most viewed public item set'); // @translate
            $html .= '<h4><a href="' . $statsBrowseUrl . '">' . $statsBrowseText . '</a></h4>';
            $stats = $analytics->mostViewedResources('item_sets', $userStatus, 1, 5);
            if (empty($stats)) {
                $html .= '<p>' . $translate('None') . '</p>';
            } else {
                $stat = reset($stats);
                $html .= '<ul>';
                $html .= sprintf($translate('%s (%d views)'), // @translate
                    $stat->linkEntity(),
                    $stat->totalHits($userStatus)
                );
                $html .= '</ul>';
            }

            $statsBrowseUrl = $url('admin/analytics/default', ['action' => 'by-download'], true);
            $statsBrowseText = $translate('Most downloaded file'); // @translate
            $html .= '<h4><a href="' . $statsBrowseUrl . '">' . $statsBrowseText . '</a></h4>';
            $stats = $analytics->mostViewedDownloads($userStatus, 1, 1);
            if (empty($stats)) {
                $html .= '<p>' . $translate('None') . '</p>';
            } else {
                $stat = reset($stats);
                $html .= '<ul>';
                $html .= sprintf($translate('%s (%d downloads)'), // @translate
                    $stat->linkEntity(),
                    $stat->totalHits($userStatus)
                );
                $html .= '</ul>';
            }

            $statsBrowseUrl = $url('admin/analytics/default', ['action' => 'by-field'], true);
            $statsBrowseText = $translate('Most frequent fields'); // @translate
            $html .= '<h4><a href="' . $statsBrowseUrl . '">' . $statsBrowseText . '</a></h4>';
            /** @var \Analytics\Api\Representation\StatRepresentation[] $results */
            foreach ([
                'referrer' => $translate('Referrer'), // @translate
                'query' => $translate('Query'), // @translate
                'user_agent' => $translate('User Agent'), // @translate
                // 'accept_language' => $translate('Full Accepted Language'), // @translate
                'language' => $translate('Language'), // @translate
            ] as $field => $label) {
                $results = $analytics->mostFrequents($field, $userStatus, 1, 1);
                $html .= '<li>';
                if (empty($results)) {
                    $html .= sprintf($translate('%s: None'), $label);
                } else {
                    $result = reset($results);
                    $html .= sprintf('%s: %s (%d%%)', sprintf('<a href="%s">%s</a>', $url('admin/analytics/default', ['action' => 'by-field'], true) . '?field=' . $field, $label), $result[$field], $result['hits'] * 100 / $totalHits);
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
        echo $html;
    }

    public function handleMainSettings(Event $event): void
    {
        $this->handleAnySettings($event, 'settings');

        // Write the .htaccess rule according to the saved setting.
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $htaccessTypes = $settings->get('analytics_htaccess_types', []);
        $this->manageHtaccess($htaccessTypes);
    }

    /**
     * Manage the .htaccess rewrite rule for download tracking.
     *
     * In read mode ($types is null): parse the .htaccess to detect current
     * tracked types, sync the setting, and display status messages.
     *
     * In write mode ($types is an array): update the .htaccess rule to match
     * the requested types. An empty array removes the rule.
     *
     * If the module Access is active with its own rule, the download rule is
     * unnecessary because Access calls Analytics directly.
     *
     * @param array|null $types Null for read mode, array for write mode.
     */
    protected function manageHtaccess(?array $types = null): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        $htaccessPath = OMEKA_PATH . '/.htaccess';
        $isWritable = is_writable($htaccessPath);
        $htaccess = file_get_contents($htaccessPath);
        if ($htaccess === false) {
            $message = new PsrMessage(
                'The file .htaccess is not readable at the root of Omeka.' // @translate
            );
            $messenger->addError($message);
            return;
        }

        $marker = '# Module Analytics: count downloads.';
        $accessMarker = '# Module Access: protect files.';
        $isWriteMode = $types !== null;

        // Check if the module Access is active with its own .htaccess rule.
        $hasAccessRule = strpos($htaccess, $accessMarker) !== false;
        $moduleManager = $services->get('Omeka\ModuleManager');
        $accessModule = $moduleManager->getModule('Access');
        $isAccessActive = $accessModule && $accessModule->getState() === \Omeka\Module\Manager::STATE_ACTIVE;

        // Parse existing rule to find currently tracked types.
        $currentTypes = [];
        $hasMarker = strpos($htaccess, $marker) !== false;
        $hasLegacyRule = false;
        if ($hasMarker) {
            // Match the RewriteRule line after the marker (skip optional comment lines).
            if (preg_match('/' . preg_quote($marker, '/') . '\s*\n(?:\s*#[^\n]*\n)*\s*RewriteRule\s+"\^files\/\(([^)]+)\)\//', $htaccess, $matches)) {
                $currentTypes = explode('|', $matches[1]);
            }
        } else {
            // Detect legacy rules (without marker) that redirect to /download/files/.
            $knownTypes = ['original', 'large', 'medium', 'square'];
            if (preg_match_all('/^\s*RewriteRule\s+.*files\/\(([^)]+)\).*\/download\/files\//m', $htaccess, $matches)) {
                foreach ($matches[1] as $group) {
                    $currentTypes = array_merge($currentTypes, explode('|', $group));
                }
                $hasLegacyRule = true;
            }
            if (preg_match_all('/^\s*RewriteRule\s+["\^]*files\/(' . implode('|', $knownTypes) . ')\/.*\/download\/files\//m', $htaccess, $matches)) {
                $currentTypes = array_merge($currentTypes, $matches[1]);
                $hasLegacyRule = true;
            }
            $currentTypes = array_values(array_unique(array_intersect($currentTypes, $knownTypes)));
        }

        $knownStandardTypes = ['original', 'large', 'medium', 'square'];

        // Read mode: sync setting from .htaccess state and display info.
        if (!$isWriteMode) {
            $standardCurrent = array_values(array_intersect($currentTypes, $knownStandardTypes));
            $customCurrent = array_values(array_diff($currentTypes, $knownStandardTypes));
            $settings->set('analytics_htaccess_types', $standardCurrent);
            $settings->set('analytics_htaccess_custom_types', implode(' ', $customCurrent));

            if ($isAccessActive && $hasAccessRule) {
                $message = new PsrMessage(
                    'The module Access is active with its own .htaccess rule. The download tracking rule is not needed because Access calls Analytics directly.' // @translate
                );
                $messenger->addSuccess($message);
            } elseif ($hasLegacyRule) {
                $message = new PsrMessage(
                    'A legacy .htaccess rule tracks file types "{types}" but is not managed by the module. Save the settings to convert it to the managed format.', // @translate
                    ['types' => implode(', ', $currentTypes)]
                );
                $messenger->addWarning($message);
            } elseif (empty($currentTypes) && !$hasAccessRule) {
                $exampleRule = $marker . "\n" . '# This rule is automatically managed by the module.' . "\n" . 'RewriteRule "^files/(original)/(.*)$" "download/files/$1/$2" [NC,L]';
                $message = new PsrMessage(
                    'No .htaccess rule is set to track downloads. To count file downloads, add the following lines in the file .htaccess at the root of Omeka, just after "RewriteEngine On":{line_break}{rule}', // @translate
                    [
                        'line_break' => '<br><pre>',
                        'rule' => htmlspecialchars($exampleRule) . '</pre>',
                    ]
                );
                $message->setEscapeHtml(false);
                $messenger->addError($message);
            } else {
                $message = new PsrMessage(
                    'The .htaccess rule is managed by the module and tracks file types: {types}.', // @translate
                    ['types' => implode(', ', $currentTypes)]
                );
                $messenger->addSuccess($message);
            }
            return;
        }

        // Write mode: if Access is active with its rule, skip the download rule.
        if ($isAccessActive && $hasAccessRule) {
            // Remove existing download rule if present.
            if ($hasMarker) {
                $htaccess = preg_replace('/' . preg_quote($marker, '/') . '\s*\n(?:\s*#[^\n]*\n)*\s*RewriteRule\s+"[^"]*"\s+"[^"]*"\s+\[[^\]]*\]\s*\n?/', '', $htaccess);
                if ($isWritable) {
                    file_put_contents($htaccessPath, $htaccess);
                }
            }
            $settings->set('analytics_htaccess_types', $types);
            $message = new PsrMessage(
                'The module Access is active with its own .htaccess rule. The download tracking rule is not needed.' // @translate
            );
            $messenger->addSuccess($message);
            return;
        }

        // Merge standard types with custom types from setting.
        $customTypesStr = $settings->get('analytics_htaccess_custom_types', '');
        $customTypes = array_filter(array_map('trim', preg_split('/[\s,|]+/', $customTypesStr)));
        $customTypes = array_filter($customTypes, fn ($v) => preg_match('/^[a-zA-Z0-9][-a-zA-Z0-9]*$/', $v));
        $allTypes = array_values(array_unique(array_merge($types, $customTypes)));

        // Build the new block (or empty if no types).
        $newBlock = '';
        if (!empty($allTypes)) {
            $typesPattern = implode('|', $allTypes);
            $newBlock = $marker . "\n" . '# This rule is automatically managed by the module.' . "\n" . 'RewriteRule "^files/(' . $typesPattern . ')/(.*)$" "download/files/$1/$2" [NC,L]';
        }

        // Nothing changed: same types and already in managed format.
        $sortedCurrent = $currentTypes;
        $sortedAll = $allTypes;
        sort($sortedCurrent);
        sort($sortedAll);
        if ($sortedCurrent === $sortedAll && !$hasLegacyRule) {
            $settings->set('analytics_htaccess_types', $types);
            return;
        }

        if (!$isWritable) {
            $settings->set('analytics_htaccess_types', $types);
            if (!empty($allTypes)) {
                $message = new PsrMessage(
                    'The file .htaccess is not writable. Add the following lines manually in the file .htaccess at the root of Omeka, just after "RewriteEngine On":{line_break}{rule}', // @translate
                    [
                        'line_break' => '<br><pre>',
                        'rule' => htmlspecialchars($newBlock) . '</pre>',
                    ]
                );
                $message->setEscapeHtml(false);
                $messenger->addWarning($message);
            } else {
                $message = new PsrMessage(
                    'The file .htaccess is not writable. Remove the lines starting with "{marker}" manually from the file .htaccess at the root of Omeka.', // @translate
                    ['marker' => $marker]
                );
                $messenger->addWarning($message);
            }
            return;
        }

        // Remove existing marker block if present (marker + optional comments + rule).
        if ($hasMarker) {
            $htaccess = preg_replace('/' . preg_quote($marker, '/') . '\s*\n(?:\s*#[^\n]*\n)*\s*RewriteRule\s+"[^"]*"\s+"[^"]*"\s+\[[^\]]*\]\s*\n?/', '', $htaccess);
        }

        // Remove legacy rules (without marker) that redirect to /download/files/.
        if ($hasLegacyRule) {
            $knownTypes = ['original', 'large', 'medium', 'square'];
            $htaccess = preg_replace('/^\s*RewriteRule\s+.*files\/\([^)]+\).*\/download\/files\/.*\n?/m', '', $htaccess);
            $htaccess = preg_replace('/^\s*RewriteRule\s+.*files\/(' . implode('|', $knownTypes) . ')\/.*\/download\/files\/.*\n?/m', '', $htaccess);
        }

        // Insert new block after "RewriteEngine On" if types are selected.
        if (!empty($newBlock)) {
            if (preg_match('/RewriteEngine\s+On\s*\n/', $htaccess, $m, PREG_OFFSET_CAPTURE)) {
                $insertPos = $m[0][1] + strlen($m[0][0]);
                $htaccess = substr_replace($htaccess, "\n" . $newBlock . "\n\n", $insertPos, 0);
            }
        }

        file_put_contents($htaccessPath, $htaccess);
        $settings->set('analytics_htaccess_types', $types);

        if (!empty($allTypes)) {
            $message = new PsrMessage(
                'The .htaccess rule has been updated to track file types: {types}.', // @translate
                ['types' => implode(', ', $allTypes)]
            );
            $messenger->addSuccess($message);
        } else {
            $message = new PsrMessage(
                'The .htaccess rule for download tracking has been removed.' // @translate
            );
            $messenger->addSuccess($message);
        }
    }

    public function handleEasyAdminJobsForm(Event $event): void
    {
        /**
         * @var \EasyAdmin\Form\CheckAndFixForm $form
         * @var \Laminas\Form\Element\Radio $process
         */
        $form = $event->getTarget();
        $form->setAttribute('data-tasks-warning', $form->getAttribute('data-tasks-warning') . ',db_analytics_index');
        $fieldset = $form->get('module_tasks');
        $process = $fieldset->get('process');
        $valueOptions = $process->getValueOptions();
        $valueOptions['db_analytics_index'] = 'Analytics: Index statistics (needed only after direct import)'; // @translate
        $process->setValueOptions($valueOptions);
    }

    public function handleEasyAdminJobs(Event $event): void
    {
        $process = $event->getParam('process');
        if ($process === 'db_analytics_index') {
            $event->setParam('job', \Analytics\Job\AggregateHits::class);
            $event->setParam('args', []);
        }
    }

    public function handleBulkImportAfter(Event $event): void
    {
        /** @var \BulkImport\Processor\AbstractFullProcessor $processor */
        $processor = $event->getTarget();
        $toImport = $processor->getParam('types') ?: [];
        if (!in_array('hits', $toImport)) {
            return;
        }

        /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
        $services = $this->getServiceLocator();
        $strategy = $services->get(\Omeka\Job\DispatchStrategy\Synchronous::class);
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);

        $logger = $event->getParam('logger');
        $logger->notice('Update of aggregated statistics: Start'); // @translate

        $dispatcher->dispatch(\Analytics\Job\AggregateHits::class, [], $strategy);

        $logger->notice('Update of aggregated statistics: Ended.'); // @translate
    }
}
