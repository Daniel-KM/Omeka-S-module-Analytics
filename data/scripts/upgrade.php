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

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.83')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.83'
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
