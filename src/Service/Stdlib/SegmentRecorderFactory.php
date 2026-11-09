<?php declare(strict_types=1);

namespace Analytics\Service\Stdlib;

use Analytics\Stdlib\SegmentRecorder;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SegmentRecorderFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null): SegmentRecorder
    {
        return new SegmentRecorder(
            $services->get('Omeka\Connection'),
            (bool) $services->get('Omeka\Settings')->get('analytics_segments')
        );
    }
}
