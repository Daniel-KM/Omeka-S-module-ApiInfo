<?php declare(strict_types=1);
namespace ApiInfo;

return [
    'view_manager' => [
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\ApiController::class => Service\Controller\ApiControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'settingsList' => Service\ControllerPlugin\SettingsListFactory::class,
            'siteSettingsList' => Service\ControllerPlugin\SiteSettingsListFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'api' => [
                'child_routes' => [
                    'info' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/infos[/:id]',
                            'constraints' => [
                                'id' => '[a-zA-Z0-9_-]+',
                            ],
                            'defaults' => [
                                'controller' => Controller\ApiController::class,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
