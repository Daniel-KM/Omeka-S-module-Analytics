<?php declare(strict_types=1);

namespace Analytics;

return [
    'service_manager' => [
        'invokables' => [
            Mvc\MvcListeners::class => Mvc\MvcListeners::class,
        ],
    ],
    'listeners' => [
        Mvc\MvcListeners::class,
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'api_adapters' => [
        'invokables' => [
            'hits' => Api\Adapter\HitAdapter::class,
            'stats' => Api\Adapter\StatAdapter::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'analytics' => Service\ViewHelper\AnalyticsFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
        'factories' => [
            Form\AnalyticsByDownloadForm::class => Service\Form\FormFactory::class,
            Form\AnalyticsByFieldForm::class => Service\Form\FormFactory::class,
            Form\AnalyticsByItemSetForm::class => Service\Form\FormFactory::class,
            Form\AnalyticsByPageForm::class => Service\Form\FormFactory::class,
            Form\AnalyticsByResourceForm::class => Service\Form\FormFactory::class,
            Form\AnalyticsBySiteForm::class => Service\Form\FormFactory::class,
            Form\AnalyticsByValueForm::class => Service\Form\FormFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'Analytics\Controller\Analytics' => Service\Controller\AnalyticsControllerFactory::class,
            'Analytics\Controller\Download' => Service\Controller\DownloadControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'analytics' => Service\ControllerPlugin\AnalyticsFactory::class,
            'logCurrentUrl' => Service\ControllerPlugin\LogCurrentUrlFactory::class,
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            'analytics' => [
                'label' => 'Analytics', // @translate
                'route' => 'admin/analytics',
                'controller' => 'Analytics',
                'action' => 'index',
                'resource' => 'Analytics\Controller\Analytics',
                'class' => 'o-icon- fa-chart-line',
                'pages' => [
                    [
                        'route' => 'admin/analytics/default',
                        'visible' => false,
                    ],
                ],
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'site' => [
                'child_routes' => [
                    'analytics' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/analytics',
                            'defaults' => [
                                '__NAMESPACE__' => 'Analytics\Controller',
                                '__SITE__' => true,
                                'controller' => 'Analytics',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'default' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:action',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                ],
                                'may_terminate' => true,
                                'child_routes' => [
                                    'output' => [
                                        'type' => \Laminas\Router\Http\Segment::class,
                                        'options' => [
                                            'route' => '.:output',
                                            'constraints' => [
                                                'output' => 'csv|ods|tsv',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'admin' => [
                'child_routes' => [
                    'analytics' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/analytics',
                            'defaults' => [
                                '__NAMESPACE__' => 'Analytics\Controller',
                                '__ADMIN__' => true,
                                'controller' => 'Analytics',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'default' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:action',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                ],
                                'may_terminate' => true,
                                'child_routes' => [
                                    'output' => [
                                        'type' => \Laminas\Router\Http\Segment::class,
                                        'options' => [
                                            'route' => '.:output',
                                            'constraints' => [
                                                'output' => 'csv|ods|tsv',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'download' => [
                'type' => \Laminas\Router\Http\Segment::class,
                'options' => [
                    // See module Access too.
                    // Manage module Archive repertory, that can use real names and subdirectories.
                    // For any filename, either use `:filename{?}`, or add a constraint `'filename' => '.+'`.
                    'route' => '/download/files/:type/:filename{?}',
                    'constraints' => [
                        'type' => '[^/]+',
                        'filename' => '.+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'Analytics\Controller',
                        'controller' => 'Download',
                        'action' => 'file',
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'shortcodes' => [
        'invokables' => [
            'stat' => Shortcode\Stat::class,
            'stat_total' => Shortcode\Stat::class,
            'stat_position' => Shortcode\Stat::class,
            'stat_vieweds' => Shortcode\Stat::class,
        ],
    ],
    'analytics' => [
        'settings' => [
            // Types of files tracked via .htaccess. Empty means not managed by the module.
            'analytics_htaccess_types' => [],
            // Custom file paths to track (for DerivativeMedia, etc.).
            'analytics_htaccess_custom_types' => '',

            // Privacy settings.
            'analytics_privacy' => 'anonymous',
            'analytics_include_bots' => false,
            // Display.
            'analytics_default_user_status_admin' => 'hits',
            'analytics_default_user_status_public' => 'anonymous',
            'analytics_disable_dashboard' => true,
            'analytics_per_page_admin' => 100,
            'analytics_per_page_public' => 10,
            // Without roles.
            'analytics_public_allow_summary' => false,
            'analytics_public_allow_browse' => false,
        ],
    ],
];
