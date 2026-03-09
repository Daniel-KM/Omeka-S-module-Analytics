<?php declare(strict_types=1);

namespace Analytics\Service\ControllerPlugin;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Analytics\Mvc\Controller\Plugin\LogCurrentUrl;

class LogCurrentUrlFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new LogCurrentUrl(
            $services
        );
    }
}
