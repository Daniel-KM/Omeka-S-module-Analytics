<?php declare(strict_types=1);

namespace Analytics\Api\Representation;

use Omeka\Api\Adapter\AdapterInterface as ApiAdapterInterface;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\AbstractResourceRepresentation;
use Analytics\View\Helper\Analytics;

/**
 * @fixme Unlike read(), findEntity() and find() don't check acl rights. So check anywhere it may be used.
 */
trait AnalyticTrait
{
    /**
     * Determine whether or not the hit is a direct download.
     *
     * Of course, only files stored locally can be hit.
     * @todo Manage a specific path.
     *
     * @return bool True if hit has a resource, even deleted.
     */
    public function isDownload(): bool
    {
        return strpos($this->resource->getUrl(), '/files/') === 0;
    }

    /**
     * Determine whether or not the page has or had a resource.
     *
     * @todo Check rights (but requires read, so long process). Preload the allowed list of ids?
     *
     * @return bool True if hit has a resource, even deleted.
     */
    public function hasEntity(): bool
    {
        return $this->resource->getEntityName()
            && $this->resource->getEntityId();
    }

    /**
     * Alias of hasEntity().
     *
     * @see self::hasEntity().
     */
    public function hasResource(): bool
    {
        return $this->resource->getEntityName()
            && $this->resource->getEntityId();
    }

    /**
     * Get the resource object if any and not deleted.
     */
    public function entityResource(): ?AbstractResourceRepresentation
    {
        $name = $this->resource->getEntityName();
        $id = $this->resource->getEntityId();
        if (empty($name) || empty($id)) {
            return null;
        }
        $adapter = $this->getApiAdapter($name);
        if (!$adapter) {
            return null;
        }
        try {
            $entity = $adapter->findEntity(['id' => $id]);
            return $adapter->getRepresentation($entity);
        } catch (NotFoundException $e) {
            return null;
        }
    }

    /**
     * Get the resource object link or a string when deleted
     */
    public function linkEntity(?string $text = null): ?string
    {
        $name = $this->resource->getEntityName();
        $id = $this->resource->getEntityId();
        if (empty($name) || empty($id)) {
            return null;
        }
        $adapter = $this->getApiAdapter($name);
        if ($adapter) {
            try {
                $entity = $adapter->findEntity(['id' => $id]);
                $representation = $adapter->getRepresentation($entity);
                if ($text === null) {
                    if (method_exists($representation, 'displayTitle')) {
                        $text = $representation->displayTitle();
                    } elseif (method_exists($representation, 'title')) {
                        $text = $representation->title();
                    } elseif (method_exists($representation, 'label')) {
                        $text = $representation->label();
                    } else {
                        $text = $this->getTranslator()->translate('[untitled]'); // @translate
                    }
                }
                return $representation->link($text);
            } catch (NotFoundException $e) {
                // Below.
            }
        }
        return sprintf('<span class="unavailable">%s</span>', $text === null
            ? sprintf('%s #%s', $this->humanResourceType(), $id)
            : $this->getViewHelper('escapeHtml')($text)
        );
    }

    /**
     * Helper to get the singular human name of the resource type.
     *
     * @param string $default Return this string if empty, or default if set.
     */
    public function humanResourceType(?string $default = null): string
    {
        /** @var \Common\Stdlib\EasyMeta $easyMeta */
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $entityName = $this->resource->getEntityName();
        return $easyMeta->resourceLabel($entityName) ?? $default ?? $entityName;
    }

    protected function getApiAdapter(?string $resourceName): ?ApiAdapterInterface
    {
        $adapterManager = $this->getServiceLocator()
            ->get('Omeka\ApiAdapterManager');
        return $adapterManager->has($resourceName)
            ? $adapterManager->get($resourceName)
            : null;
    }

    protected function getStatistic(): Analytics
    {
        return $this->getServiceLocator()->get('ViewHelperManager')->get('analytics');
    }
}
