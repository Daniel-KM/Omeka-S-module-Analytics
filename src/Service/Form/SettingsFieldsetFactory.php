<?php declare(strict_types=1);

namespace Analytics\Service\Form;

use Analytics\Form\SettingsFieldset;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SettingsFieldsetFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null): SettingsFieldset
    {
        $fieldset = new SettingsFieldset(null, $options ?? []);
        $fieldset->setCleanHitsDone(
            (bool) $services->get('Omeka\Settings')->get('analytics_clean_hits_done')
        );
        return $fieldset;
    }
}
