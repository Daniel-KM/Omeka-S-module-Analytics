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

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');
        $message = new \Omeka\Stdlib\Message(
            $translator->translate('To compute access to files, you must add a rule in file .htaccess at the root of Omeka. See %sreadme%s.'), // @translate
            '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-Analytics" target="_blank" rel="noopener">', '</a>'
        );
        $message->setEscapeHtml(false);
        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $messenger->addWarning($message);
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
        $this->warnConfig();

        $this->handleAnySettings($event, 'settings');
    }

    /**
     * @see \Access\Module::warnConfig()
     * @see \Analytics\Module::warnConfig()
     */
    protected function warnConfig(): void
    {
        $htaccess = file_get_contents(OMEKA_PATH . '/.htaccess');
        if (strpos($htaccess, '/download/') || strpos($htaccess, '/access/')) {
            return;
        }

        $services = $this->getServiceLocator();
        $message = new PsrMessage(
            'To get analytics about files, you must add a rule in file .htaccess at the root of Omeka. See {link}readme{link_end}.', // @translate
            [
                'link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-Analytics" target="_blank" rel="noopener">',
                'link_end' => '</a>',
            ]
        );
        $message->setEscapeHtml(false);
        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $messenger->addError($message);
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
