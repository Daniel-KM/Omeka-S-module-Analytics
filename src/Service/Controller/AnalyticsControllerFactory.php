<?php declare(strict_types=1);

namespace Analytics\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Analytics\Controller\AnalyticsController;

class AnalyticsControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new AnalyticsController(
            $services->get('Omeka\Connection')
        );
    }
}
