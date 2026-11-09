<?php declare(strict_types=1);

namespace Analytics\Mvc\Controller\Plugin;

use Psr\Container\ContainerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Request;
use Analytics\Entity\Hit;

/**
 * Adapted:
 * @see \Analytics\Mvc\MvcListeners
 * @see \Analytics\Mvc\Controller\Plugin\LogCurrentUrl
 */
class LogCurrentUrl extends AbstractPlugin
{
    /**
     * @var \Psr\Container\ContainerInterface
     */
    protected $services;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    /**
     * Log the current public url.
     *
     * Note: normally, this plugin should not be used because all requests are
     * now managed via the MVC event "Finish", that is triggered in all cases.
     *
     * @see \Analytics\Mvc\MvcListeners
     */
    public function __invoke(): ?Hit
    {
        // Don't store server ping or internal redirect on root or some proxies.
        if (empty($_SERVER['HTTP_HOST'])
            // || ($_SERVER['REQUEST_URI'] === '/' && $_SERVER['QUERY'] === '' && $_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['CONTENT_LENGTH'] === '0')
        ) {
            return null;
        }

        // Log the statistic for the url even if the file is missing or protected.

        // A head request is a probe of a file by a player or a bot, not a view.
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'HEAD' || $method === 'OPTIONS') {
            return null;
        }

        // Don't log admin pages.

        /** @var \Omeka\Mvc\Status $status */
        $status = $this->services->get('Omeka\Status');

        if ($status->isAdminRequest()) {
            return null;
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
            // Module Access: check of the rights by the image server.
            'access-authorize',
            // Module CSS Editor.
            'site/css-editor',
        ])) {
            return null;
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
            $urlAdminTop = $this->services->get('ControllerPluginManager')->get('url')->fromRoute('admin', [], ['force_canonical' => true]) . '/';
            if (strpos($referrer, $urlAdminTop) === 0) {
                return null;
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
                return null;
            }
        }

        // The requests of the parts of a media are not hits: the view of an
        // image is its info.json and the view of a file is its download. The
        // requested parts may be aggregated as segments (option).
        $fileRoutes = ['download', 'access-file', 'access-resource-file'];
        $mediaPartRoutes = [
            'imageserver/id',
            'imageserver/media',
            'imageserver/media-bad',
            'imageserver/placeholder',
            'imageserver/resolve',
            'mediaserver/id',
            'mediaserver/media',
            'mediaserver/media-bad',
            'mediaserver/placeholder',
            // Module Iiif Search: the parts of the pages are loaded
            // automatically by the viewers.
            'iiifsearch/alto',
            'iiifsearch/alto-page',
            'iiifsearch/annotation-list',
            'iiifsearch/annotation-page',
            'iiifsearch/hocr-page',
        ];
        $isFileRoute = in_array($routeName, $fileRoutes);
        if ($isFileRoute || in_array($routeName, $mediaPartRoutes)) {
            /** @var \Analytics\Stdlib\SegmentRecorder $segmentRecorder */
            $segmentRecorder = $this->services->get('Analytics\SegmentRecorder');
            if ($isFileRoute && $segmentRecorder->isEnabled()) {
                $entity = $hitAdapter->currentEntity();
                if ($entity['id']) {
                    $slice = $this->currentTimeSlice($routeMatch);
                    if ($slice !== null) {
                        $segmentRecorder->record(
                            $entity['name'],
                            $entity['id'],
                            \Analytics\Stdlib\SegmentRecorder::TYPE_TIME,
                            [$slice]
                        );
                    }
                }
            }
            if (!$isFileRoute) {
                // The regions of the images are recorded by the image server.
                return null;
            }
        }

        // A file is requested many times for a single view: probe then
        // download, ranges of a video, preload of a player, replay. Only the
        // first request of a visitor within the deduplication window counts.
        if ($isFileRoute && $hitAdapter->isRecentHit()) {
            return null;
        }

        $request = new Request(Request::CREATE, 'hits');
        $request
            ->setOption('initialize', false)
            ->setOption('finalize', false)
        ;
        try {
            // The entity manager is automatically flushed by default.
            return $hitAdapter->create($request)->getContent();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            // An issue may occur, so skip when:
            // - the controller is not found for an image;
            // - the same image is loaded multiple times on the same page.
            return null;
        } catch (\Throwable $e) {
            $logger = $this->services->get('Omeka\Logger');
            $logger->err(
                'Exception when storing hit/stat: {message}', // @translate
                ['message' => $e->getMessage()]
            );
            return null;
        }
    }

    /**
     * Slice of the requested file for the byte offset of the current request.
     */
    protected function currentTimeSlice($routeMatch): ?int
    {
        $type = (string) $routeMatch->getParam('type');
        $filename = (string) $routeMatch->getParam('filename');
        if ($type === '' || $filename === '' || strpos($filename, '..') !== false) {
            return null;
        }
        $config = $this->services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $filepath = $basePath . '/' . $type . '/' . $filename;
        $fileSize = file_exists($filepath) ? (int) filesize($filepath) : 0;
        $offset = 0;
        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if (preg_match('~^bytes=(\d+)-~', $range, $matches)) {
            $offset = (int) $matches[1];
        }
        return (new \Analytics\Stdlib\SegmentBucketer())->timeSlice($offset, $fileSize);
    }

}
