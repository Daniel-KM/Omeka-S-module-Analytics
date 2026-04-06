<?php declare(strict_types=1);

namespace Analytics\Controller;

use Common\Stdlib\PsrMessage;
use Doctrine\DBAL\Connection;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Analytics\Entity\Stat;

/**
 * Controller to browse Analytics.
 *
 * @todo Create an single output (or two) for all statistics (with or without period).
 */
class AnalyticsController extends AbstractActionController
{
    use AnalyticsTrait;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var string
     */
    protected $userStatus;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function indexAction()
    {
        $isAdminRequest = $this->status()->isAdminRequest();
        $settings = $this->settings();
        $this->userStatus = $isAdminRequest
            ? $settings->get('analytics_default_user_status_admin')
            : $settings->get('analytics_default_user_status_public');

        $results = [];
        $time = time();

        $translate = $this->plugins->get('translate');

        $results['all'] = $this->analyticsPeriod();

        $results['today'] = $this->analyticsPeriod(strtotime('today'));

        $results['history'][$translate('Last year')] = $this->analyticsPeriod( // @translate
            strtotime('-1 year', strtotime(date('Y-1-1', $time))),
            strtotime(date('Y-1-1', $time) . ' - 1 second')
        );
        $results['history'][$translate('Last month')] = $this->analyticsPeriod( // @translate
            strtotime('-1 month', strtotime(date('Y-m-1', $time))),
            strtotime(date('Y-m-1', $time) . ' - 1 second')
        );
        $results['history'][$translate('Last week')] = $this->analyticsPeriod( // @translate
            strtotime("previous week"),
            strtotime("previous week + 6 days")
        );
        $results['history'][$translate('Yesterday')] = $this->analyticsPeriod( // @translate
            strtotime('-1 day', strtotime(date('Y-m-d', $time))),
            strtotime('-1 day', strtotime(date('Y-m-d', $time)))
        );

        $results['current'][$translate('This year')] = // @translate
        $this->analyticsPeriod(strtotime(date('Y-1-1', $time)));
        $results['current'][$translate('This month')] =  // @translate
        $this->analyticsPeriod(strtotime(date('Y-m-1', $time)));
        $results['current'][$translate('This week')] = // @translate
        $this->analyticsPeriod(strtotime('this week'));
        $results['current'][$translate('This day')] = // @translate
        $this->analyticsPeriod(strtotime('today'));

        foreach ([365 => null, 30 => null, 7 => null, 1 => null] as $start => $endPeriod) {
            $startPeriod = strtotime("- {$start} days");
            $label = ($start == 1)
                ? $translate('Last 24 hours') // @translate
                : sprintf($translate('Last %s days'), $start); // @translate
            $results['rolling'][$label] = $this->analyticsPeriod($startPeriod, $endPeriod);
        }

        if ($this->userIsAllowed('Analytics\Controller\Analytics', 'by-page')) {
            /** @var \Analytics\View\Helper\Analytics $analytics */
            $analytics = $this->viewHelpers()->get('analytics');
            $results['most_viewed_pages'] = $analytics->mostViewedPages(null, $this->userStatus, 1, 10);
            $results['most_viewed_resources'] = $analytics->mostViewedResources(null, $this->userStatus, 1, 10);
            $results['most_viewed_item_sets'] = $analytics->mostViewedResources('item_sets', $this->userStatus, 1, 10);
            $results['most_viewed_downloads'] = $analytics->mostViewedDownloads($this->userStatus, 1, 10);
            $results['most_frequent_fields']['referrer'] = $analytics->mostFrequents('referrer', $this->userStatus, 1, 10);
            $results['most_frequent_fields']['query'] = $analytics->mostFrequents('query', $this->userStatus, 1, 10);
            $results['most_frequent_fields']['user_agent'] = $analytics->mostFrequents('user_agent', $this->userStatus, 1, 10);
            // $results['most_frequent_fields']['accept_language'] = $analytics->mostFrequents('accept_language', $this->userStatus, 1, 10);
            $results['most_frequent_fields']['language'] = $analytics->mostFrequents('language', $this->userStatus, 1, 10);
            // Make json results utf-8.
            foreach ($results['most_frequent_fields']['query'] as &$val) {
                $val['query'] = json_encode(json_decode($val['query'], true), 320);
            }
            unset($val);
        }

        $view = new ViewModel([
            'results' => $results,
            'userStatus' => $this->userStatus,
        ]);

        return $view
            ->setTemplate($isAdminRequest ? 'analytics/admin/analytics/index' : 'analytics/site/analytics/index');
    }

    /**
     * Redirect to the 'by-page' action.
     */
    public function browseAction()
    {
        $query = $this->params()->fromRoute();
        $query['action'] = 'by-page';
        $isSiteRequest = $this->status()->isSiteRequest();
        return $this->redirect()->toRoute($isSiteRequest ? 'site/analytics/default' : 'admin/analytics/default', $query);
    }

    public function bySiteAction()
    {
        // FIXME Stats by site has not been fully checked.
        // TODO Add a column "site_id" in table "stat".
        // TODO Factorize with byItemSetAction?
        // TODO Move the process into view helper Analytics.
        // TODO Enlarge byItemSet to byResource (since anything is resource).

        $years  = $this->listYears('resource', null, null, true);

        $isAdminRequest = $this->status()->isAdminRequest();

        $data = $this->params()->fromQuery() ?: $this->params()->fromPost();
        $query = [];

        /** @var \Analytics\Form\AnalyticsBySiteForm $form */
        $form = $this->getForm(\Analytics\Form\AnalyticsBySiteForm::class, [
            'years' => $years,
        ]);

        if ($data) {
            $form->setData($data);
            // The @ fixes a warning because new DateTime->getLastErrors() may be false, not an array.
            /** @see \Laminas\Validator\Date::convertString() */
            if (@$form->isValid()) {
                $query = $form->getData();
            } else {
                $this->messenger()->addFormErrors($form);
            }
            unset($query['csrf'], $query['submit']);
        }

        // Sort is not in the form.
        $query['sort_by'] = empty($data['sort_by']) ? null : $data['sort_by'];
        $query['sort_order'] = isset($data['sort_order']) && strtolower($data['sort_order']) === 'asc' ? 'asc' : 'desc';

        $resourceType = $query['entity_name'] ?? null;
        $queryQuery = $query['query'] ?? null;
        if (is_string($queryQuery)) {
            parse_str($query['query'], $queryQuery);
        }
        $queryQuery = $queryQuery ?: null;
        $year = empty($query['year']) || !is_numeric($query['year']) ? null : (int) $query['year'];
        $month = empty($query['month']) || !is_numeric($query['month']) ? null : (int) $query['month'];
        $since = $query['since'] ?? null;
        $until = $query['until'] ?? null;
        $sortBy = empty($query['sort_by']) ? 'hits' : $query['sort_by'];
        $sortOrder = isset($query['sort_order']) && strtolower($query['sort_order']) === 'asc' ? 'asc' : 'desc';

        $appendDates = $this->whereDate($year, $month, [], []);
        $bind = $appendDates['bind'];
        $types = $appendDates['types'];
        $force = $appendDates['force'];
        $whereYear = $appendDates['whereYear'];
        $whereMonth = $appendDates['whereMonth'];
        $whereIn = '';

        $settings = $this->settings();
        $userStatus = $isAdminRequest
            ? $settings->get('analytics_default_user_status_admin')
            : $settings->get('analytics_default_user_status_public');
        if ($userStatus === 'anonymous') {
            $whereStatus = 'AND hit.user_id = 0';
        } elseif ($userStatus === 'identified') {
            $whereStatus = 'AND hit.user_id <> 0';
        } else {
            $whereStatus = '';
        }

        if ($resourceType && !in_array($resourceType, ['', 'resources', 'site_pages'])) {
            $whereEntityName = 'hit.entity_name = :entity_name';
            $bind['entity_name'] = $resourceType;
            $types['entity_name'] = \Doctrine\DBAL\ParameterType::STRING;
        } else {
            $whereEntityName = '1 = 1';
        }

        if ($queryQuery && !in_array($resourceType, ['', 'resources', 'site_pages'])) {
            $subIds = array_keys($this->api()->search($resourceType, $queryQuery, ['returnScalar' => 'id'])->getContent());
            if ($subIds) {
                $whereIn = 'AND hit.entity_id IN (:entity_ids)';
                $bind['entity_ids'] = $subIds;
                $types['entity_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
            } else {
                $whereIn = 'AND hit.entity_id = -1';
            }
        }

        if ($since && $until) {
            $whereBetween = 'AND hit.created BETWEEN :since AND :until';
            $bind['since'] = $since . ' 00:00:00';
            $bind['until'] = $until . ' 23:59:59';
        } elseif ($since) {
            $whereBetween = 'AND hit.created >= :since';
            $bind['since'] = $since . ' 00:00:00';
        } elseif ($until) {
            $whereBetween = 'AND hit.created <= :until';
            $bind['until'] = $until . ' 23:59:59';
        } else {
            $whereBetween = '';
        }

        $sql = <<<SQL
            SELECT hit.site_id, COUNT(hit.id) AS total_hits
            FROM hit hit $force
            WHERE $whereEntityName
                $whereStatus
                $whereYear
                $whereMonth
                $whereBetween
                $whereIn
            GROUP BY hit.site_id
            ORDER BY total_hits ASC
            ;
            SQL;

        $hitsPerSite = $this->connection->executeQuery($sql, $bind, $types)->fetchAllKeyValue();

        $removedSite = $this->translate('[Removed site #%d]'); // @translate

        $api = $this->api();
        $results = [];
        foreach ($hitsPerSite as $siteId => $hits) {
            try {
                $siteTitle = $api->read('sites', ['id' => $siteId])->getContent()->title();
            } catch (\Throwable $e) {
                $siteTitle = sprintf($removedSite, $siteId);
            }
            $results[] = [
                'site' => $siteTitle,
                'hits' => $hits,
            ];
        }

        $this->paginator(count($results));

        // TODO Manage special sort fields.
        $this->orderByColumn = [
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ];
        usort($results, [$this, $sortOrder === 'hits' ? 'orderByColumnNumber' : 'orderByColumnString']);

        $view = new ViewModel([
            'form' => $form,
            'type' => 'site',
            'results' => $results,
            'years' => $years,
            'yearFilter' => $year,
            'monthFilter' => $month,
        ]);
        return $view
            ->setTemplate($isAdminRequest ? 'analytics/admin/analytics/by-site' : 'analytics/site/analytics/by-site');
    }

    /**
     * Browse rows by page action.
     *
     * @todo Factorize page, resource and download and keep one tab.
     */
    public function byPageAction()
    {
        $isAdminRequest = $this->status()->isAdminRequest();
        $settings = $this->settings();

        $data = $this->params()->fromQuery() ?: $this->params()->fromPost();
        $query = [];

        $userStatus = $isAdminRequest
            ? $settings->get('analytics_default_user_status_admin')
            : $settings->get('analytics_default_user_status_public');

        $defaultSorts = ['hits_anonymous' => 'hits_anonymous', 'hits_identified' => 'hits_identified'];
        $userStatusBrowse = $defaultSorts[$userStatus] ?? 'hits';
        $this->setBrowseDefaults($userStatusBrowse);

        $years  = $this->listYears('hit', null, null, true);

        /** @var \Analytics\Form\AnalyticsByPageForm $form */
        $form = $this->getForm(\Analytics\Form\AnalyticsByPageForm::class, [
            'years' => $years,
        ]);

        if ($data) {
            $form->setData($data);
            // The @ fixes a warning because new DateTime->getLastErrors() may be false, not an array.
            /** @see \Laminas\Validator\Date::convertString() */
            if (@$form->isValid()) {
                $query = $form->getData();
            } else {
                $this->messenger()->addFormErrors($form);
            }
            unset($query['csrf'], $query['submit']);
        }

        // Sort is not in the form.
        $query['sort_by'] = empty($data['sort_by']) ? 'hits' : $data['sort_by'];
        $query['sort_order'] = isset($data['sort_order']) && strtolower($data['sort_order']) === 'asc' ? 'asc' : 'desc';

        $query['type'] = Stat::TYPE_PAGE;
        $query['user_status'] = $userStatus;
        $query['has_resource'] ??= '';
        $query['entity_name'] ??= '';

        $year = empty($query['year']) || !is_numeric($query['year']) ? null : (int) $query['year'];
        $month = empty($query['month']) || !is_numeric($query['month']) ? null : (int) $query['month'];
        if ($year || $month) {
            if (!$year) {
                $year = end($years);
            }
            if ($month) {
                $query['since'] = sprintf('%04d-%02d', $year, $month) . '-01 00:00:00';
                $query['until'] = sprintf('%04d-%02d', $year, $month) . '-31 23:59:59';
            } else {
                $query['since'] = sprintf('%04d', $year) . '-01-01 00:00:00';
                $query['until'] = sprintf('%04d', $year) . '-12-31 23:59:59';
            }
            unset($query['year'], $query['month']);
        }

        $columns = empty($query['columns'])
            /** @see \Analytics\Form\AnalyticsByPageForm */
            ? ['url', 'hits', 'resource', 'date']
            : $query['columns'];
        unset($query['columns']);

        // Page is not in the form.
        $query['page'] = empty($data['page']) ? 1 : (int) $data['page'];

        // Use the aggregated table "stat" when possible.
        $quick = empty($query['since']) && empty($query['until']);
        if ($quick) {
            $response = $this->api()->search('stats', $query);
            $stats = $response->getContent();

            $this->paginator($response->getTotalResults());

            $view = new ViewModel([
                'form' => $form,
                'resources' => $stats ?? null,
                'stats' => $stats,
                'userStatus' => $userStatus,
                'type' => Stat::TYPE_PAGE,
                'columns' => $columns,
            ]);
            return $view
                ->setTemplate($isAdminRequest ? 'analytics/admin/analytics/by-stat' : 'analytics/site/analytics/by-stat');
        }

        $query['type'] = $query['entity_name'] === 'site_pages' ? 'site_pages' : '';

        // Same as \Statisctics\Form\AnalyticsByPageForm columns.
        $headers = [
            'url' => 'Page', // @translate
            'hits' => 'Hits', // @translate
            'hits_anonymous' => 'Anonymous', // @translate
            'hits_identified' => 'Identified', // @translate
            'resource' => 'Resource', // @translate
            'date' => 'Last date', // @translate
        ];
        $headers = $columns ? array_intersect_key($headers, array_flip($columns)) : $headers;

        $columns = array_combine(array_keys($headers), array_keys($headers));
        if (isset($columns['resource'])
            || isset($columns['entity_name'])
            || isset($columns['entity_id'])
        ) {
            $headers['available'] = 'Available'; // @translate
            $columns['available'] = 'available';
        }

        if (isset($columns['resource'])) {
            $headers['title'] = 'Title'; // @translate
            $columns['title'] = 'title';
        }

        $output = $this->params()->fromRoute('output');
        if ($output) {
            $page = null;
            $perPage = null;
        } else {
            $page = $query['page'];
            $perPage = empty($query['per_page']) ? 100: (int) $query['per_page'];
        }

        // This is a array with flat sub-arrays.
        $table = $this->analytics()->viewedHitsFull($query, $columns, $page, $perPage);
        $this->paginator($this->analytics()->lastTotal());

        if ($output) {
            $result = $this->exportTable($table, $headers, $output);
            if ($result) {
                return $result;
            }
        }

        $view = new ViewModel([
            'form' => $form,
            'headers' => $headers,
            'table' => $table,
            'userStatus' => $userStatus,
            'type' => Stat::TYPE_PAGE,
        ]);
        return $view
            ->setTemplate($isAdminRequest ? 'analytics/admin/analytics/by-hit' : 'analytics/site/analytics/by-hit');
    }

    /**
     * Browse rows by resource action.
     */
    public function byResourceAction()
    {
        $isAdminRequest = $this->status()->isAdminRequest();
        $settings = $this->settings();

        $data = $this->params()->fromQuery() ?: $this->params()->fromPost();
        $query = [];

        $userStatus = $isAdminRequest
            ? $settings->get('analytics_default_user_status_admin')
            : $settings->get('analytics_default_user_status_public');

        $defaultSorts = ['hits_anonymous' => 'hits_anonymous', 'hits_identified' => 'hits_identified'];
        $userStatusBrowse = $defaultSorts[$userStatus] ?? 'hits';
        $this->setBrowseDefaults($userStatusBrowse);

        $years  = $this->listYears('hit', null, null, true);

        /** @var \Analytics\Form\AnalyticsByResourceForm $form */
        $form = $this->getForm(\Analytics\Form\AnalyticsByResourceForm::class, [
            'years' => $years,
        ]);

        if ($data) {
            $form->setData($data);
            // The @ fixes a warning because new DateTime->getLastErrors() may be false, not an array.
            /** @see \Laminas\Validator\Date::convertString() */
            if (@$form->isValid()) {
                $query = $form->getData();
            } else {
                $this->messenger()->addFormErrors($form);
            }
            unset($query['csrf'], $query['submit']);
        }

        // Sort is not in the form.
        $query['sort_by'] = empty($data['sort_by']) ? 'hits' : $data['sort_by'];
        $query['sort_order'] = isset($data['sort_order']) && strtolower($data['sort_order']) === 'asc' ? 'asc' : 'desc';

        // The query includes the resource type and a query.

        $query['type'] = Stat::TYPE_RESOURCE;
        $query['user_status'] = $userStatus;

        $year = empty($query['year']) || !is_numeric($query['year']) ? null : (int) $query['year'];
        $month = empty($query['month']) || !is_numeric($query['month']) ? null : (int) $query['month'];
        if ($year || $month) {
            if (!$year) {
                $year = end($years);
            }
            if ($month) {
                $query['since'] = sprintf('%04d-%02d', $year, $month) . '-01 00:00:00';
                $query['until'] = sprintf('%04d-%02d', $year, $month) . '-31 23:59:59';
            } else {
                $query['since'] = sprintf('%04d', $year) . '-01-01 00:00:00';
                $query['until'] = sprintf('%04d', $year) . '-12-31 23:59:59';
            }
            unset($query['year'], $query['month']);
        }

        $columns = empty($query['columns'])
            /** @see \Analytics\Form\AnalyticsByResourceForm */
            ? ['url', 'hits', 'resource', 'entity_name', 'resource_class_id', 'resource_template_id']
            : $query['columns'];
        unset($query['columns']);

        // Page is not in the form.
        $query['page'] = empty($data['page']) ? 1 : (int) $data['page'];

        // Use the aggregated table "stat" when possible.
        $quick = empty($query['since']) && empty($query['until']);
        if ($quick) {
            $response = $this->api()->search('stats', $query);
            $stats = $response->getContent();

            $this->paginator($response->getTotalResults());

            $view = new ViewModel([
                'form' => $form,
                'resources' => $stats ?? null,
                'stats' => $stats,
                'userStatus' => $userStatus,
                'type' => Stat::TYPE_RESOURCE,
                'columns' => $columns,
            ]);
            return $view
                ->setTemplate($isAdminRequest ? 'analytics/admin/analytics/by-stat' : 'analytics/site/analytics/by-stat');
        }

        $query['type'] = 'resources';

        // Same as \Statisctics\Form\AnalyticsByResourceForm columns.
        $headers = [
            'url' => 'Page', // @translate
            'hits' => 'Hits', // @translate
            'hits_anonymous' => 'Anonymous', // @translate
            'hits_identified' => 'Identified', // @translate
            'resource' => 'Resource', // @translate
            'entity_name' => 'Resource type', // @translate
            'resource_class_id' => 'Resource class', // @translate
            'resource_template_id' => 'Resource template', // @translate
            'item_sets' => 'Item sets', // @translate
            'media_type' => 'Media type', // @translate
            'date' => 'Last date', // @translate
        ];
        $headers = $columns ? array_intersect_key($headers, array_flip($columns)) : $headers;

        $columns = array_combine(array_keys($headers), array_keys($headers));
        if (isset($columns['resource'])
            || isset($columns['entity_name'])
            || isset($columns['entity_id'])
        ) {
            $headers['available'] = 'Available'; // @translate
            $columns['available'] = 'available';
        }

        if (isset($columns['resource'])) {
            $headers['title'] = 'Title'; // @translate
            $columns['title'] = 'title';
        }

        $output = $this->params()->fromRoute('output');
        if ($output) {
            $page = null;
            $perPage = null;
        } else {
            $page = $query['page'];
            $perPage = empty($query['per_page']) ? 100: (int) $query['per_page'];
        }

        // This is a array with flat sub-arrays.
        $table = $this->analytics()->viewedHitsFull($query, $columns, $page, $perPage);
        $this->paginator($this->analytics()->lastTotal());

        if ($output) {
            $result = $this->exportTable($table, $headers, $output);
            if ($result) {
                return $result;
            }
        }

        $view = new ViewModel([
            'form' => $form,
            'headers' => $headers,
            'table' => $table,
            'userStatus' => $userStatus,
            'type' => Stat::TYPE_RESOURCE,
        ]);
        return $view
            ->setTemplate($isAdminRequest ? 'analytics/admin/analytics/by-hit' : 'analytics/site/analytics/by-hit');
    }

    /**
     * Browse rows by download action.
     */
    public function byDownloadAction()
    {
        $isAdminRequest = $this->status()->isAdminRequest();
        $settings = $this->settings();

        $data = $this->params()->fromQuery() ?: $this->params()->fromPost();
        $query = [];

        $userStatus = $isAdminRequest
            ? $settings->get('analytics_default_user_status_admin')
            : $settings->get('analytics_default_user_status_public');

        $defaultSorts = ['hits_anonymous' => 'hits_anonymous', 'hits_identified' => 'hits_identified'];
        $userStatusBrowse = $defaultSorts[$userStatus] ?? 'hits';
        $this->setBrowseDefaults($userStatusBrowse);

        $years  = $this->listYears('hit', null, null, true);

        /** @var \Analytics\Form\AnalyticsByDownloadForm $form */
        $form = $this->getForm(\Analytics\Form\AnalyticsByDownloadForm::class, [
            'years' => $years,
        ]);

        if ($data) {
            $form->setData($data);
            // The @ fixes a warning because new DateTime->getLastErrors() may be false, not an array.
            /** @see \Laminas\Validator\Date::convertString() */
            if (@$form->isValid()) {
                $query = $form->getData();
            } else {
                $this->messenger()->addFormErrors($form);
            }
            unset($query['csrf'], $query['submit']);
        }

        // Sort is not in the form.
        $query['sort_by'] = empty($data['sort_by']) ? 'hits' : $data['sort_by'];
        $query['sort_order'] = isset($data['sort_order']) && strtolower($data['sort_order']) === 'asc' ? 'asc' : 'desc';

        // The query includes a query, but need a resource type.
        $query['entity_name'] = 'media';

        $query['type'] = Stat::TYPE_DOWNLOAD;
        $query['user_status'] = $userStatus;
        $query['file_type'] ??= [];
        $query['file_type'] = empty($query['file_type']) || in_array('', $query['file_type'])
            ? []
            : array_unique($query['file_type']);

        $year = empty($query['year']) || !is_numeric($query['year']) ? null : (int) $query['year'];
        $month = empty($query['month']) || !is_numeric($query['month']) ? null : (int) $query['month'];
        if ($year || $month) {
            if (!$year) {
                $year = end($years);
            }
            if ($month) {
                $query['since'] = sprintf('%04d-%02d', $year, $month) . '-01 00:00:00';
                $query['until'] = sprintf('%04d-%02d', $year, $month) . '-31 23:59:59';
            } else {
                $query['since'] = sprintf('%04d', $year) . '-01-01 00:00:00';
                $query['until'] = sprintf('%04d', $year) . '-12-31 23:59:59';
            }
            unset($query['year'], $query['month']);
        }

        $columns = empty($query['columns'])
            /** @see \Analytics\Form\AnalyticsByDownloadForm */
            ? ['url', 'hits', 'resource', 'media_type', 'date']
            : $query['columns'];
        unset($query['columns']);

        // Page is not in the form.
        $query['page'] = empty($data['page']) ? 1 : (int) $data['page'];

        // Use the aggregated table "stat" when possible.
        $quick = empty($query['since']) && empty($query['until']);
        if ($quick) {
            $response = $this->api()->search('stats', $query);
            $stats = $response->getContent();

            $this->paginator($response->getTotalResults());

            $view = new ViewModel([
                'form' => $form,
                'resources' => $stats ?? null,
                'stats' => $stats,
                'userStatus' => $userStatus,
                'type' => Stat::TYPE_DOWNLOAD,
                'columns' => $columns,
            ]);
            return $view
                ->setTemplate($isAdminRequest ? 'analytics/admin/analytics/by-stat' : 'analytics/site/analytics/by-stat');
        }

        $query['type'] = 'files';

        // Same as \Statisctics\Form\AnalyticsByDownloadForm columns.
        $headers = [
            'url' => 'Page', // @translate
            'hits' => 'Hits', // @translate
            'hits_anonymous' => 'Anonymous', // @translate
            'hits_identified' => 'Identified', // @translate
            'resource' => 'Resource', // @translate
            'resource_class_id' => 'Resource class', // @translate
            'resource_template_id' => 'Resource template', // @translate
            'media_type' => 'Media type', // @translate
            'date' => 'Last date', // @translate
        ];
        $headers = $columns ? array_intersect_key($headers, array_flip($columns)) : $headers;

        $columns = array_combine(array_keys($headers), array_keys($headers));
        if (isset($columns['resource'])
            || isset($columns['entity_name'])
            || isset($columns['entity_id'])
        ) {
            $headers['available'] = 'Available'; // @translate
            $columns['available'] = 'available';
        }

        if (isset($columns['resource'])) {
            $headers['title'] = 'Title'; // @translate
            $columns['title'] = 'title';
        }

        $output = $this->params()->fromRoute('output');
        if ($output) {
            $page = null;
            $perPage = null;
        } else {
            $page = $query['page'];
            $perPage = empty($query['per_page']) ? 100: (int) $query['per_page'];
        }

        // This is a array with flat sub-arrays.
        $table = $this->analytics()->viewedHitsFull($query, $columns, $page, $perPage);
        $this->paginator($this->analytics()->lastTotal());

        if ($output) {
            $result = $this->exportTable($table, $headers, $output);
            if ($result) {
                return $result;
            }
        }

        $view = new ViewModel([
            'form' => $form,
            'headers' => $headers,
            'table' => $table,
            'userStatus' => $userStatus,
            'type' => Stat::TYPE_DOWNLOAD,
        ]);
        return $view
            ->setTemplate($isAdminRequest ? 'analytics/admin/analytics/by-hit' : 'analytics/site/analytics/by-hit');
    }

    /**
     * Browse rows by field action.
     */
    public function byFieldAction()
    {
        $isAdminRequest = $this->status()->isAdminRequest();
        $settings = $this->settings();

        $userStatus = $isAdminRequest
            ? $settings->get('analytics_default_user_status_admin')
            : $settings->get('analytics_default_user_status_public');

        $data = $this->params()->fromQuery() ?: $this->params()->fromPost();
        $query = [];

        /** @var \Analytics\Form\AnalyticsByFieldForm $form */
        $form = $this->getForm(\Analytics\Form\AnalyticsByFieldForm::class);

        if ($data) {
            $form->setData($data);
            if ($form->isValid()) {
                $query = $form->getData();
            } else {
                $this->messenger()->addFormErrors($form);
            }
            unset($query['csrf'], $query['submit']);
        }

        // Sort is not in the form.
        $query['sort_by'] = empty($data['sort_by']) ? 'hits' : $data['sort_by'];
        $query['sort_order'] = isset($data['sort_order']) && strtolower($data['sort_order']) === 'asc' ? 'asc' : 'desc';

        $query['field'] = empty($query['field']) ? 'referrer' : $query['field'];
        $field = $query['field'];

        $query = $this->defaultSort($query, [$field, 'hits'], 'hits');

        $currentPage = isset($query['page']) ? (int) $query['page'] : null;
        $resourcesPerPage = $isAdminRequest
            ? (int) $settings->get('pagination_per_page', 25)
            : (int) $this->siteSettings()->get('pagination_per_page', 25);

        // The query includes the resource type and a query.

        // Don't use api, because this is a synthesis, not a list of resources.
        /** @var \Analytics\View\Helper\Analytics $analytics */
        $analytics = $this->viewHelpers()->get('analytics');
        $results = $analytics->frequents($query, $currentPage, $resourcesPerPage);
        $totalResults = $analytics->countFrequents($query);
        $totalHits = $analytics->totalHits([], $userStatus);
        $totalNotEmpty = $analytics->totalHits(['field' => $field, 'not_empty' => $field], $userStatus);
        $this->paginator($totalResults);

        switch ($field) {
            default:
            case 'referrer':
                $labelField = $this->translate('External Referrers'); // @translate
                break;
            case 'query':
                $labelField = $this->translate('Queries'); // @translate
                break;
            case 'user_agent':
                $labelField = $this->translate('Browsers'); // @translate
                break;
            case 'accept_language':
                $labelField = $this->translate('Full Accepted Languages'); // @translate
                break;
            case 'language':
                $labelField = $this->translate('Accepted Languages'); // @translate
                break;
        }

        $view = new ViewModel([
            'form' => $form,
            'type' => 'field',
            'field' => $field,
            'labelField' => $labelField,
            'results' => $results,
            'totalHits' => $totalHits,
            'totalNotEmpty' => $totalNotEmpty,
            'userStatus' => $userStatus,
        ]);
        return $view
            ->setTemplate($isAdminRequest ? 'analytics/admin/analytics/by-field' : 'analytics/site/analytics/by-field');
    }

    public function byItemSetAction()
    {
        // FIXME Stats by item set has not been fully checked.
        // TODO Move the process into view helper Analytics.
        // TODO Enlarge byItemSet to byResource (since anything is resource).

        $years  = $this->listYears('resource', null, null, true);

        $isAdminRequest = $this->status()->isAdminRequest();

        $data = $this->params()->fromQuery() ?: $this->params()->fromPost();
        $query = [];

        /** @var \Analytics\Form\AnalyticsByItemSetForm $form */
        $form = $this->getForm(\Analytics\Form\AnalyticsByItemSetForm::class, [
            'years' => $years,
        ]);
        $form->remove('entity_name');
        $form->remove('query');

        if ($data) {
            $form->setData($data);
            // The @ fixes a warning because new DateTime->getLastErrors() may be false, not an array.
            /** @see \Laminas\Validator\Date::convertString() */
            if (@$form->isValid()) {
                $query = $form->getData();
            } else {
                $this->messenger()->addFormErrors($form);
            }
            unset($query['csrf'], $query['submit']);
        }

        // Sort is not in the form.
        $query['sort_by'] = empty($data['sort_by']) ? null : $data['sort_by'];
        $query['sort_order'] = isset($data['sort_order']) && strtolower($data['sort_order']) === 'asc' ? 'asc' : 'desc';

        $year = empty($query['year']) || !is_numeric($query['year']) ? null : (int) $query['year'];
        $month = empty($query['month']) || !is_numeric($query['month']) ? null : (int) $query['month'];
        $since = $query['since'] ?? null;
        $until = $query['until'] ?? null;
        $sortBy = empty($query['sort_by']) ? 'hits' : $query['sort_by'];
        $sortOrder = isset($query['sort_order']) && strtolower($query['sort_order']) === 'asc' ? 'asc' : 'desc';

        $appendDates = $this->whereDate($year, $month, [], []);
        $bind = $appendDates['bind'];
        $types = $appendDates['types'];
        $force = $appendDates['force'];
        $whereYear = $appendDates['whereYear'];
        $whereMonth = $appendDates['whereMonth'];

        $settings = $this->settings();
        $userStatus = $isAdminRequest
            ? $settings->get('analytics_default_user_status_admin')
            : $settings->get('analytics_default_user_status_public');
        if ($userStatus === 'anonymous') {
            $whereStatus = 'AND hit.user_id = 0';
        } elseif ($userStatus === 'identified') {
            $whereStatus = 'AND hit.user_id <> 0';
        } else {
            $whereStatus = '';
        }

        if ($since && $until) {
            $whereBetween = 'AND hit.created BETWEEN :since AND :until';
            $bind['since'] = $since . ' 00:00:00';
            $bind['until'] = $until . ' 23:59:59';
        } elseif ($since) {
            $whereBetween = 'AND hit.created >= :since';
            $bind['since'] = $since . ' 00:00:00';
        } elseif ($until) {
            $whereBetween = 'AND hit.created <= :until';
            $bind['until'] = $until . ' 23:59:59';
        } else {
            $whereBetween = '';
        }

        $sql = <<<SQL
            SELECT item_item_set.item_set_id, COUNT(hit.id) AS total_hits
            FROM hit hit $force
            JOIN item_item_set ON hit.entity_id = item_item_set.item_id
            WHERE hit.entity_name = "items"
                $whereStatus
                $whereYear
                $whereMonth
                $whereBetween
            GROUP BY item_item_set.item_set_id
            ORDER BY total_hits ASC
            ;
            SQL;
        $hitsPerItemSet = $this->connection->executeQuery($sql, $bind, $types)->fetchAllKeyValue();

        $removedItemSet = $this->translate('[Removed item set #%d]'); // @translate

        $api = $this->api();
        $results = [];
        // TODO Check and integrate statistics for item set tree (with performance).
        if (false && $this->plugins()->has('itemSetsTree')) {
            $itemSetIds = $api->search('item_sets', [], ['returnScalar', 'id'])->getContent();
            foreach ($itemSetIds as $itemSetId) {
                $hitsSub = $this->getHitsByItemSet($hitsPerItemSet, $itemSetId);
                if ($hitsSub > 0) {
                    try {
                        $itemSetTitle = $api->read('item_sets', ['id' => $itemSetId])->getContent()->displayTitle();
                    } catch (\Throwable $e) {
                        $itemSetTitle = sprintf($removedItemSet, $itemSetId);
                    }
                    $results[] = [
                        'item-set' => $itemSetTitle,
                        'hits' => $hitsPerItemSet[$itemSetId] ?? 0,
                        'hits_sub' => '',
                    ];
                }
            }
        } else {
            foreach ($hitsPerItemSet as $itemSetId => $hits) {
                try {
                    $itemSetTitle = $api->read('item_sets', ['id' => $itemSetId])->getContent()->displayTitle();
                } catch (\Throwable $e) {
                    $itemSetTitle = sprintf($removedItemSet, $itemSetId);
                }
                $results[] = [
                    'item-set' => $itemSetTitle,
                    'hits' => $hits,
                ];
            }
        }

        $this->paginator(count($results));

        // TODO Manage special sort fields.
        $this->orderByColumn = [
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ];
        usort($results, [$this, $sortOrder === 'hits' ? 'orderByColumnNumber' : 'orderByColumnString']);

        $years = $this->listYears('hit', null, null, false);

        $view = new ViewModel([
            'form' => $form,
            'type' => 'item-set',
            'results' => $results,
            'years' => $years,
            'yearFilter' => $year,
            'monthFilter' => $month,
        ]);
        return $view
            ->setTemplate($isAdminRequest ? 'analytics/admin/analytics/by-item-set' : 'analytics/site/analytics/by-item-set');
    }

    public function byValueAction()
    {
        // FIXME Stats by value has not been fully checked.
        // TODO Move the process into view helper Analytics.
        // TODO Enlarge byItemSet to byResource (since anything is resource).
        // TODO Here, only items are analyzed.

        $years  = $this->listYears('resource', null, null, true);

        $isAdminRequest = $this->status()->isAdminRequest();

        $data = $this->params()->fromQuery() ?: $this->params()->fromPost();
        $query = [];

        /** @var \Analytics\Form\AnalyticsByValueForm $form */
        $form = $this->getForm(\Analytics\Form\AnalyticsByValueForm::class, [
            'years' => $years,
        ]);

        if ($data) {
            $form->setData($data);
            if ($form->isValid()) {
                $query = $form->getData();
            } else {
                $this->messenger()->addFormErrors($form);
            }
            unset($query['csrf'], $query['submit']);
        }

        // Sort is not in the form.
        $query['sort_by'] = empty($data['sort_by']) ? null : $data['sort_by'];
        $query['sort_order'] = isset($data['sort_order']) && strtolower($data['sort_order']) === 'asc' ? 'asc' : 'desc';

        $resourceType = $query['entity_name'] ?? null;
        $queryQuery = $query['query'] ?? null;
        if (is_string($queryQuery)) {
            parse_str($query['query'], $queryQuery);
        }
        $queryQuery = $queryQuery ?: null;
        $year = empty($query['year']) || !is_numeric($query['year']) ? null : (int) $query['year'];
        $month = empty($query['month']) || !is_numeric($query['month']) ? null : (int) $query['month'];
        $property = $query['property'] ?? null;
        $typeFilter = $query['value_type'] ?? null;
        $byPeriodFilter = $query['by_period'] ?? 'all';
        $sortBy = empty($query['sort_by']) ? 'hits' : $query['sort_by'];
        $sortOrder = isset($query['sort_order']) && strtolower($query['sort_order']) === 'asc' ? 'asc' : 'desc';

        $process = true;

        $settings = $this->settings();
        $userStatus = $isAdminRequest
            ? $settings->get('analytics_default_user_status_admin')
            : $settings->get('analytics_default_user_status_public');
        if ($userStatus === 'anonymous') {
            $whereStatus = 'AND hit.user_id = 0';
        } elseif ($userStatus === 'identified') {
            $whereStatus = 'AND hit.user_id <> 0';
        } else {
            $whereStatus = '';
        }

        if ($property) {
            $joinProperty = ' AND property_id = :property_id';
            $bind['property_id'] = $this->easyMeta()->propertyId($property);
            $types['property_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
        } elseif ($data) {
            $process = false;
            $this->messenger()->addError(new PsrMessage('A property is required to get statistics.')); // @translate
        }

        switch ($byPeriodFilter) {
            case 'year':
                $periods = $this->listYears('hit', $year, $year, true);
                break;
            case 'month':
                if ($year && $month) {
                    $periods = $this->listYearMonths('hit', (int) sprintf('%04d%02d', $year, $month), (int) sprintf('%04d%02d', $year, $month), true);
                } elseif ($year) {
                    $periods = $this->listYearMonths('hit', (int) sprintf('%04d01', $year), (int) sprintf('%04d12', $year), true);
                } elseif ($month) {
                    $periods = null;
                    if ($data) {
                        $this->messenger()->addError(new PsrMessage('A year is required to get details by month.')); // @translate
                    }
                } else {
                    $periods = $this->listYearMonths('hit', null, null, true);
                }
                break;
            case 'all':
            default:
                $periods = [];
                break;
        }

        $view = new ViewModel([
            'form' => $form,
            'type' => 'value',
            'results' => [],
            'years' => $this->listYears('hit', null, null, true),
            'periods' => $periods,
            'yearFilter' => $year,
            'monthFilter' => $month,
            'propertyFilter' => $property,
            'valueTypeFilter' => $typeFilter,
            'byPeriodFilter' => $byPeriodFilter,
        ]);
        $view
            ->setTemplate($isAdminRequest ? 'analytics/admin/analytics/by-value' : 'analytics/site/analytics/by-value');

        if ($periods === null
            || !$property
            || !$process
        ) {
            return $view;
        }

        if ($resourceType && !in_array($resourceType, ['', 'resources', 'site_pages'])) {
            $filterEntityName = true;
            $whereEntityName = 'hit.entity_name = :entity_name';
        } else {
            $filterEntityName = false;
            $whereEntityName = '1 = 1';
        }

        if ($queryQuery && !in_array($resourceType, ['', 'resources', 'site_pages'])) {
            $subIds = $this->api()->search($resourceType, $queryQuery, ['returnScalar' => 'id'])->getContent();
            if ($subIds) {
                $whereIn = 'AND hit.entity_id IN (:entity_ids)';
            } else {
                $whereIn = 'AND hit.entity_id = -1';
            }
        } else {
            $subIds = null;
            $whereIn = '';
        }

        // TODO There is no pagination currently in stats by value.

        // TODO Manage special sort fields.
        // TODO Add a type filter for all, or no type filter.
        switch ($typeFilter) {
            default:
            case 'value':
                $joinResource = '';
                $selectValue = 'value.value AS "value", "" AS "label"';
                $typeFilterValue = 'value.value';
                $whereFilterValue = <<<'SQL'
                    AND value.value IS NOT NULL
                    AND value.value <> ""
                    AND (value.uri IS NULL OR value.uri = "")
                    AND (value.value_resource_id IS NULL OR value.value_resource_id = "")
                    SQL;
                break;
            case 'resource':
                $joinResource = 'LEFT JOIN resource ON resource.id = value.value_resource_id';
                $selectValue = 'value.value_resource_id AS "value", resource.title AS "label"';
                $typeFilterValue = 'value.value_resource_id';
                $whereFilterValue = <<<'SQL'
                    AND value.value_resource_id IS NOT NULL
                    AND value.value_resource_id <> 0
                    AND (value.value IS NULL OR value.value= "")
                    AND (value.uri IS NULL OR value.uri = "")
                    SQL;
                break;
            case 'uri':
                $joinResource = '';
                $selectValue = 'value.uri AS "value", value.value AS "label"';
                $typeFilterValue = 'value.uri';
                $whereFilterValue = <<<'SQL'
                    AND value.uri IS NOT NULL
                    AND value.uri <> ""
                    AND (value.value_resource_id IS NULL OR value.value_resource_id = "")
                    SQL;
                break;
        }

        // FIXME The results are doubled when the property has duplicate values for a resource, so fix it or warn about deduplicating values regularly (module BulkEdit).

        $this->orderByColumn = [
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ];

        $baseBind = $bind;
        $baseTypes = $types;
        if ($periods) {
            // TODO Use a single query instead of a loop, so use periods as columns.
            $results = [];
            foreach ($periods as $period => $isEmpty) {
                if (empty($isEmpty)) {
                    $results[$period] = [];
                    continue;
                }

                // TODO Manage "force index" via query builder.
                // $qb = $this->connection->createQueryBuilder();
                if ($byPeriodFilter === 'year') {
                    $yearPeriod = (int) $period;
                    $monthPeriod = null;
                } else {
                    $yearPeriod = (int) substr((string) $period, 0, 4);
                    $monthPeriod = (int) substr((string) $period, 4, 2);
                }
                $appendDates = $this->whereDate($yearPeriod, $monthPeriod, $baseBind, $baseTypes);
                $bind = $appendDates['bind'];
                $types = $appendDates['types'];
                $force = $appendDates['force'];
                $whereYear = $appendDates['whereYear'];
                $whereMonth = $appendDates['whereMonth'];
                if ($filterEntityName) {
                    $bind['entity_name'] = $resourceType;
                    $types['entity_name'] = \Doctrine\DBAL\ParameterType::STRING;
                }
                if ($whereIn) {
                    $bind['entity_ids'] = $subIds;
                    $types['entity_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
                }

                $sql = <<<SQL
                    SELECT $selectValue, COUNT(hit.id) AS hits, "" AS hits_sub
                    FROM hit hit $force
                    JOIN value ON hit.entity_id = value.resource_id
                    $joinProperty
                    $joinResource
                    WHERE $whereEntityName
                        $whereStatus
                        $whereYear
                        $whereMonth
                        $whereFilterValue
                        $whereIn
                    GROUP BY $typeFilterValue
                    ORDER BY hits DESC
                    ;
                    SQL;
                $result = $this->connection->executeQuery($sql, $bind, $types)->fetchAllAssociative();

                usort($result, [$this, $sortOrder === 'hits' ? 'orderByColumnNumber' : 'orderByColumnString']);

                $results[$period] = $result;
            }

            $hasValueLabel = in_array($typeFilter, ['resource', 'uri']);
            $results = $this->mergeResultsByValue($results, $hasValueLabel);
        } else {
            // TODO Manage "force index" via query builder.
            // $qb = $this->connection->createQueryBuilder();
            $appendDates = $this->whereDate($year, $month, $baseBind, $baseTypes);
            $bind = $appendDates['bind'];
            $types = $appendDates['types'];
            $force = $appendDates['force'];
            $whereYear = $appendDates['whereYear'];
            $whereMonth = $appendDates['whereMonth'];
            if ($filterEntityName) {
                $bind['entity_name'] = $resourceType;
                $types['entity_name'] = \Doctrine\DBAL\ParameterType::STRING;
            }
            if ($whereIn) {
                $bind['entity_ids'] = $subIds;
                $types['entity_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
            }

            $sql = <<<SQL
                SELECT $selectValue, COUNT(hit.id) AS hits, "" AS hits_sub
                FROM hit hit $force
                JOIN value ON hit.entity_id = value.resource_id
                $joinProperty
                $joinResource
                WHERE $whereEntityName
                    $whereStatus
                    $whereYear
                    $whereMonth
                    $whereFilterValue
                    $whereIn
                GROUP BY $typeFilterValue
                ORDER BY hits DESC
                ;
                SQL;
            $results = $this->connection->executeQuery($sql, $bind, $types)->fetchAllAssociative();

            // TODO Reinclude sort order inside sql.
            usort($results, [$this, 'orderByColumn']);

            // TODO Use the same format for all queries to simplify view.
            // $results['all'] = $results;
        }

        // TODO There is no pagination currently in stats by value.
        $this->paginator(count($results));

        $view->setVariable('results', $results);
        return $view;
    }

    /**
     * Helper to get all stats of a period.
     *
     * @todo Move the view helper Analytics.
     *
     * @param int $startPeriod Number of days before today (default is all).
     * @param int $endPeriod Number of days before today (default is now).
     * @return array
     */
    protected function analyticsPeriod(?int $startPeriod = null, ?int $endPeriod = null): array
    {
        $query = [];
        if ($startPeriod) {
            $query['since'] = date('Y-m-d 00:00:00', $startPeriod);
        }
        if ($endPeriod) {
            $query['until'] = date('Y-m-d 23:59:59', $endPeriod);
        }

        // Speed the computation for count via api.
        $query['limit'] = 0;

        $api = $this->api();
        if ($this->status()->isAdminRequest()) {
            // TODO Use a single query (see version for Omeka Classic).
            $query['user_status'] = 'anonymous';
            $anonymous = $api->search('hits', $query, ['initialize' => false, 'finalize' => false])->getTotalResults();
            $query['user_status'] = 'identified';
            $identified = $api->search('hits', $query, ['initialize' => false, 'finalize' => false])->getTotalResults();
            return [
                'anonymous' => $anonymous,
                'identified' => $identified,
                'total' => $anonymous + $identified,
            ];
        }

        $query['user_status'] = $this->userStatus ?: 'hits';
        return [
            'total' => $api->search('hits', $query, ['initialize' => false, 'finalize' => false])->getTotalResults(),
        ];
    }

    /**
     * @fixme Finalize integration of item set tree.
     */
    protected function getHitsByItemSet($hitsPerItemSet, $itemSetId): int
    {
        $childrenHits = 0;
        $childItemSetIds = $this->api()->search('item_sets_tree_edge', [], ['returnScalar' => 'id'])->getChildCollections($itemSetId);
        foreach ($childItemSetIds as $childItemSetId) {
            $childrenHits += $this->getHitsByItemSet($hitsPerItemSet, $childItemSetId);
        }
        return ($hitsPerItemSet[$itemSetId] ?? 0) + $childrenHits;
    }

    protected function defaultSort(array $query, array $allowedSorts = [], string $defaultSort = 'hits'): array
    {
        $sortBy = $query['sort_by'] ?? null;
        if (empty($sortBy) || !in_array($sortBy, $allowedSorts)) {
            $query['sort_by'] = $defaultSort;
        }
        $sortOrder = $query['sort_order'] ?? null;
        if (empty($sortOrder) || !in_array(strtolower($sortOrder), ['asc', 'desc'])) {
            $query['sort_order'] = 'desc';
        }
        return $query;
    }

    /**
     * Get the results for all values for all periods, so fill empty hits.
     */
    protected function mergeResultsByValue(array $results, bool $hasValueLabel = false): array
    {
        // Each result by period contains value, label, hits, inclusive hits.
        // The table of values allows to sort hits by totals directly.
        $valuesMaxCounts = [];
        // This value is the merged results.
        $valuesByPeriod = [];
        foreach ($results as $period => $periodResults) {
            foreach ($periodResults as $result) {
                $v = $result['value'];
                if ($hasValueLabel) {
                    $valuesByPeriod[$v]['label'] = $result['label'];
                }
                $valuesByPeriod[$v]['hits'][$period] = $result['hits'];
                $valuesMaxCounts[$v] = isset($valuesMaxCounts[$v]) ? max($valuesMaxCounts[$v], $result['hits']) : $result['hits'];
            }
        }
        asort($valuesMaxCounts);
        $valuesMaxCounts = array_reverse($valuesMaxCounts, true);
        return array_replace($valuesMaxCounts, $valuesByPeriod);
    }

    /**
     * Prepare a query to filter by date.
     *
     * Require doctrine DBAL, not ORM, that does no support "extract".
     */
    protected function whereDate(?int $year = null, ?int $month = null, array $bind = [], array $types = []): array
    {
        if ($year || $month) {
            // This is the doctrine hashed name index for the column "created".
            $force = 'FORCE INDEX FOR JOIN (`IDX_5AD22641B23DB7B8`)';
            if ($year && $month) {
                $whereYear = 'AND EXTRACT(YEAR_MONTH FROM hit.created) = :year_month';
                $bind['year_month'] = sprintf('%04d%02d', $year, $month);
                $types['year_month'] = \Doctrine\DBAL\ParameterType::INTEGER;
            } elseif ($year) {
                $whereYear = 'AND EXTRACT(YEAR FROM hit.created) = :year';
                $bind['year'] = $year;
                $types['year'] = \Doctrine\DBAL\ParameterType::INTEGER;
            } elseif ($month) {
                $whereMonth = 'AND EXTRACT(MONTH FROM hit.created) = :month';
                $bind['month'] = $month;
                $types['month'] = \Doctrine\DBAL\ParameterType::INTEGER;
            }
        }
        return [
            'bind' => $bind,
            'types' => $types,
            'force' => $force ?? '',
            'whereYear' => $whereYear ?? '',
            'whereMonth' => $whereMonth ?? '',
        ];
    }
}
