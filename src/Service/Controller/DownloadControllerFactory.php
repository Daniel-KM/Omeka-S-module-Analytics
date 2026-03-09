<?php declare(strict_types=1);

namespace Analytics\Service\Controller;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Analytics\Controller\DownloadController;

class DownloadControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new DownloadController(
            $services->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files'
        );
    }
}
