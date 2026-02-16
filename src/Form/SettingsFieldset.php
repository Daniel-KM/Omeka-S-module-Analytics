<?php declare(strict_types=1);

namespace Analytics\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'Analytics'; // @translate

    protected $elementGroups = [
        'analytics' => 'Analytics', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'analytics')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'analytics_privacy',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Level of privacy for new hits', // @translate
                    'value_options' => [
                        'anonymous' => 'Anonymous', // @translate
                        'hashed' => 'Hashed IP', // @translate
                        'partial_1' => 'Partial IP (first hex)', // @translate
                        'partial_2' => 'Partial IP (first 2 hexs)', // @translate
                        'partial_3' => 'Partial IP (first 3 hexs)', // @translate
                        'clear' => 'Clear IP', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'analytics_privacy',
                    'value' => 'anonymous',
                ],
            ])
            ->add([
                'name' => 'analytics_include_bots',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Include crawlers/bots', // @translate
                    'info' => 'By checking this box, all hits which user agent contains the term "bot", "crawler", "spider", etc. will be included.', // @translate
                ],
                'attributes' => [
                    'id' => 'analytics_include_bots',
                ],
            ])

            ->add([
                'name' => 'analytics_default_user_status_admin',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'User status for admin pages', // @translate
                    'value_options' => [
                        'hits' => 'Total hits', // @translate
                        'anonymous' => 'Anonymous', // @translate
                        'identified' => 'Identified users', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'analytics_default_user_status_admin',
                ],
            ])
            ->add([
                'name' => 'analytics_default_user_status_public',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'User status for public pages', // @translate
                    'value_options' => [
                        'hits' => 'Total hits', // @translate
                        'anonymous' => 'Anonymous', // @translate
                        'identified' => 'Identified users', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'analytics_default_user_status_public',
                ],
            ])
            ->add([
                'name' => 'analytics_disable_dashboard',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Disable analytics on admin dashboard', // @translate
                ],
                'attributes' => [
                    'id' => 'analytics_disable_dashboard',
                ],
            ])
            ->add([
                'name' => 'analytics_per_page_admin',
                'type' => Element\Number::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Results per page (admin)', // @translate
                ],
                'attributes' => [
                    'id' => 'analytics_per_page_admin',
                    'min' => 0,
                ],
            ])
            ->add([
                'name' => 'analytics_per_page_public',
                'type' => Element\Number::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Results per page (public)', // @translate
                ],
                'attributes' => [
                    'id' => 'analytics_per_page_public',
                    'min' => 0,
                ],
            ])

            ->add([
                'name' => 'analytics_public_allow_summary',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Allow public to access analytics summary', // @translate
                ],
                'attributes' => [
                    'id' => 'analytics_public_allow_summary',
                ],
            ])
            ->add([
                'name' => 'analytics_public_allow_browse',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Allow public to access detailled analytics', // @translate
                ],
                'attributes' => [
                    'id' => 'analytics_public_allow_browse',
                ],
            ])
        ;
    }
}
