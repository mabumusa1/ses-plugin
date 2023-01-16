<?php

return [
    'name'        => 'AWS SES Support for Mautic',
    'description' => 'Adds AWS SES as supported mailer for Mautic.',
    'version'     => '1.0',
    'author'      => 'Steer Campaign',
    'routes'      => [
        'main'   => [
            'plugin_scmailerses_admin' => [
                'path'       => '/scmailerses/admin',
                'controller' => 'MauticPlugin\ScMailerSesBundle\Controller\ScMailerSesController::indexAction',
                'method'     => 'GET',
            ],
            'plugin_scmailerses_delete' => [
                'path'       => '/scmailerses/delete',
                'controller' => 'MauticPlugin\ScMailerSesBundle\Controller\ScMailerSesController::deleteAction',
                'method'     => 'POST',
            ],
        ],
    ],
    'menu'        => [
        'admin' => [
            'plugin.scmailerses.admin' => [
                'route'     => 'plugin_scmailerses_admin',
                'iconClass' => 'fa-gears',
                'access'    => 'admin',
                'priority'  => 60,
            ],
        ],
    ],
];
