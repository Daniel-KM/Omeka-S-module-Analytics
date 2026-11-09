<?php declare(strict_types=1);

namespace Analytics\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'Analytics'; // @translate

    /**
     * @var bool
     */
    protected $cleanHitsDone = false;

    public function setCleanHitsDone(bool $cleanHitsDone): self
    {
        $this->cleanHitsDone = $cleanHitsDone;
        return $this;
    }

    protected $elementGroups = [
        'analytics' => 'Analytics', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'analytics')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'analytics_htaccess_skip',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Do not modify .htaccess (manage Apache redirections manually)', // @translate
                    'info' => 'When checked, the module will not write or update the rewrite rule in the root .htaccess. Add the rules manually (vhost or .htaccess) to redirect file requests through the download controller.', // @translate
                ],
                'attributes' => [
                    'id' => 'analytics_htaccess_skip',
                ],
            ])
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
                'name' => 'analytics_trusted_proxies',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Trusted reverse proxies', // @translate
                    'info' => 'IPs or CIDR ranges (space or comma separated) of reverse proxies in front of the server. Headers X-Forwarded-For and X-Real-IP are honored only when the direct peer matches this list. Empty disables proxy header trust (safer when no proxy is in front).', // @translate
                ],
                'attributes' => [
                    'id' => 'analytics_trusted_proxies',
                    'placeholder' => '127.0.0.1 10.0.0.0/8',
                ],
            ])
            ->add([
                'name' => 'analytics_deduplication_minutes',
                'type' => Element\Number::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Minutes to count the requests of a file by a visitor as a single hit', // @translate
                    'info' => 'A file is requested many times for a single view: probe, ranges of an audio or video file, preload, replay. A long media read continuously still counts a new hit when the window expires, so increase it for long videos or audio books. Empty or zero disables the deduplication.', // @translate
                ],
                'attributes' => [
                    'id' => 'analytics_deduplication_minutes',
                    'min' => 0,
                ],
            ])
            ->add([
                'name' => 'analytics_segments',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Record the requested zones of images and parts of audio and video files (heat map)', // @translate
                    'info' => 'The requests of the tiles of the images and the partial requests of the audio and video files are aggregated by month in the table "analytics_segment", to identify the most viewed zones and the most played parts. They are never counted as hits.', // @translate
                ],
                'attributes' => [
                    'id' => 'analytics_segments',
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

        // The checkbox to clean the past hits is displayed until the cleaning
        // was done once. The task remains available in the module Easy Admin.
        if (!$this->cleanHitsDone) {
            $this->add([
                'name' => 'analytics_clean_hits',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'analytics',
                    'label' => 'Clean past hits once (deduplicate files, remove regions of images)', // @translate
                    'info' => 'Run the task that deduplicates the past hits of files and removes the hits of the regions of the images (tiles), then rebuilds the statistics, so the counts of files and images will decrease. The removed hits are archived in the table "hit_duplicate", to check then to empty, or to restore. The task is also available in the module Easy Admin.', // @translate
                ],
                'attributes' => [
                    'id' => 'analytics_clean_hits',
                ],
            ]);
        }
    }
}
