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
                'name' => 'analytics_htaccess_types',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'File types to track downloads via .htaccess', // @translate
                    'info' => 'Select the file types that should be tracked by an Apache rewrite rule in the root .htaccess. The rule redirects direct file access through the download controller to count downloads. When the module Access is active with its own rule, the download rule is unnecessary because Access calls Analytics directly.', // @translate
                    'value_options' => [
                        'original' => 'original', // @translate
                        'large' => 'large', // @translate
                        'medium' => 'medium', // @translate
                        'square' => 'square', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'analytics_htaccess_types',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'analytics_htaccess_custom_types',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Custom file paths to track via .htaccess', // @translate
                    'info' => 'Additional file subdirectories to track, for example for the module DerivativeMedia (mp3, mp4, etc.). Separate paths with spaces.', // @translate
                ],
                'attributes' => [
                    'id' => 'analytics_htaccess_custom_types',
                    'placeholder' => 'mp3 mp4 webm ogg pdf',
                ],
            ])

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
