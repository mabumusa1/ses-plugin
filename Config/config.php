<?php

return [
    'name'        => 'AWS SES Support for Mautic',
    'description' => 'Adds AWS SES as supported mailer for Mautic.',
    'version'     => '1.0',
    'author'      => 'Steer Campaign',
    'services'    => [
        'others' => [
            'ses+api' => [
                'class'        => \MauticPlugin\ScMailerSesBundle\Mailer\Transport\SesTransportExtension::class,
                'arguments'    => [
                    'mautic.email.ses.callback',
                ],
                'tagArguments' => [
                    \Mautic\EmailBundle\Model\TransportType::TRANSPORT_ALIAS   => 'mautic.email.config.mailer_transport.ses',
                    \Mautic\EmailBundle\Model\TransportType::FIELD_HOST        => false,
                    \Mautic\EmailBundle\Model\TransportType::FIELD_PORT        => false,
                    \Mautic\EmailBundle\Model\TransportType::FIELD_USER        => true,
                    \Mautic\EmailBundle\Model\TransportType::FIELD_PASSWORD    => true,
                    \Mautic\EmailBundle\Model\TransportType::TRANSPORT_OPTIONS => 'MauticPlugin\ScMailerSesBundle\Form\Type\ConfigType',
                    \Mautic\EmailBundle\Model\TransportType::DSN_CONVERTOR     => 'MauticPlugin\ScMailerSesBundle\Helper\DsnSesConvertor',
                ],
                'tag'          => 'mautic.email.transport_extension',
            ],
            'mautic.email.ses.callback' => [
                'class'     => \MauticPlugin\ScMailerSesBundle\Mailer\Callback\AmazonCallback::class,
                'arguments' => [
                    'monolog.logger.mautic',
                    'mautic.http.client',
                    'translator',
                    'mautic.email.model.transport_callback',
                ],
            ],
        ],
    ],
];
