<?php declare(strict_types=1);

namespace Analytics\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Fieldset;

class SiteSettingsFieldset extends Fieldset
{
    protected $label = 'Analytics'; // @translate

    protected $elementGroups = [
        'themes_old' => 'Old themes', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'analytics')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'analytics_placement',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'themes_old',
                    'label' => 'Analytics (old themes)', // @translate
                    'value_options' => [
                        'after/items' => 'Item show', // @translate
                        'after/media' => 'Media show', // @translate
                        'after/item_sets' => 'Item set show', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'analytics_placement',
                    'required' => false,
                ],
            ]);
    }
}
