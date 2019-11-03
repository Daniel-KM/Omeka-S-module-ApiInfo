<?php
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
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/infos[/:id]',
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
