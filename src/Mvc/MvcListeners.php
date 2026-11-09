<?php declare(strict_types=1);

namespace Analytics\Mvc;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Api\Request;

class MvcListeners extends AbstractListenerAggregate
{
    /**
     * @var \Psr\Container\ContainerInterface
     */
    protected $services;

    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        // A ViewEvent allows to add a script easierly, but it is better to use
        // MvcEvent Dispatch because this is the only one that is use in all
        // cases, including json api, xml output for module oai-pmh and error.
        // Cannot be used:
        // Events Bootstrap and Route: all services are not yet loaded.
        // Event Finish: some file requests don't finish, for example when a big
        // file is viewed but stopped before end.
        // TODO Manage Dispatch error.
        $events->attach(
            MvcEvent::EVENT_DISPATCH,
            [$this, 'processLogCurrentUrl'],
            // Lately to support rerouting (module Clean Url).
            1000
        );
    }

    /**
     * Adapted:
     * @see \Analytics\Mvc\MvcListeners
     * @see \Analytics\Mvc\Controller\Plugin\LogCurrentUrl
     */
    public function processLogCurrentUrl(MvcEvent $mvcEvent): void
    {
        // In case of error or a internal redirection, there may be two calls.
        // TODO Is it still useful?
        static $processed = false;

        if ($processed) {
            return;
        }

        // TODO Is check of http_host before logging url still useful?
        // Don't store server ping or internal redirect on root or some proxies.
        if (empty($_SERVER['HTTP_HOST'])
            // || ($_SERVER['REQUEST_URI'] === '/' && $_SERVER['QUERY'] === '' && $_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['CONTENT_LENGTH'] === '0')
        ) {
            return;
        }

        // Log the statistic for the url even if the file is missing or protected.

        // A head request is a probe of a file by a player or a bot, not a view.
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'HEAD' || $method === 'OPTIONS') {
            return;
        }

        $processed = true;

        $this->services = $mvcEvent->getApplication()->getServiceManager();

        // Don't log admin pages.

        /** @var \Omeka\Mvc\Status $status */
        $status = $this->services->get('Omeka\Status');

        if ($status->isAdminRequest()) {
            return;
        }

        $routeMatch = $status->getRouteMatch();
        $routeName = $routeMatch->getMatchedRouteName();

        // Don't log some routes.
        if (in_array($routeName, [
            // Omeka S.
            'install',
            'migrate',
            'login',
            'logout',
            'create-password',
            'forgot-password',
            // Module CSS Editor.
            'site/css-editor',
        ])) {
            return;
        }

        // Don't log download request for admin users but non-admins and guests.
        // It's not simple to determine from server if the request comes from a
        // visitor on the site or something else. So use referrer and identity.
        $referrer = $_SERVER['HTTP_REFERER'] ?? null;
        if ($referrer
            // Guest user should not be logged.
            && strpos($referrer, '/admin/')
            && in_array($routeName, ['access-file', 'access-resource-file', 'download'])
            // Only check if there is a user: no useless check for users who
            // can't go admin (guest), and checked below anyway.
            && $this->services->get('Omeka\AuthenticationService')->getIdentity()
            // Slower but manage extra roles and modules permissions.
            // && in_array($user->getRole(), ['global_admin', 'site_admin', 'editor', 'reviewer']);
            // Guests don't have the right to view all resources, neither author
            // and researcher.
            && $this->services->get('Omeka\Acl')->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all')
        ) {
            // Url is not available before Event dispatch, so rebuild admin top
            // url with ServerUrl.
            // $urlAdminTop = $this->services->get('ControllerPluginManager')->get('url')->fromRoute('admin', [], ['force_canonical' => true]) . '/';
            $serverUrlHelper = $this->services->get('ViewHelperManager')->get('ServerUrl');
            $urlAdminTop = $serverUrlHelper('/admin/');
            if (strpos($referrer, $urlAdminTop) === 0) {
                return;
            }
        }

        // For performance, use the adapter directly, not the api.
        // TODO Use direct sql query to store hits?

        /** @var \Analytics\Api\Adapter\HitAdapter $hitAdapter */
        $hitAdapter = $this->services->get('Omeka\ApiAdapterManager')->get('hits');

        $includeBots = (bool) $this->services->get('Omeka\Settings')->get('analytics_include_bots');
        if (empty($includeBots)) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            if ($userAgent && $hitAdapter->isBot($userAgent)) {
                return;
            }
        }

        // A file is requested many times for a single view: probe then
        // download, ranges of a video, preload of a player, replay. Only the
        // first request of a visitor within the deduplication window counts.
        if (in_array($routeName, ['download', 'access-file', 'access-resource-file'])
            && $hitAdapter->isRecentHit()
        ) {
            return;
        }

        $request = new Request(Request::CREATE, 'hits');
        $request
            ->setOption('initialize', false)
            ->setOption('finalize', false)
        ;
        try {
            // The entity manager is automatically flushed by default.
            $hitAdapter->create($request);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            // An issue may occur, so skip when the stat is not unique:
            // - the controller is not found for an image;
            // - the same image is loaded multiple times on the same page.
            // - the slug of a site page may have been updated.
        } catch (\Throwable $e) {
            $logger = $this->services->get('Omeka\Logger');
            $logger->err(
                'Exception when storing hit/stat: {message}', // @translate
                ['message' => $e->getMessage()]
            );
        }
    }
}
