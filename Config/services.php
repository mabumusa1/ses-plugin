<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = [
        'Mailer/Transport/SesTransport.php',
    ];
    $services->set('mailer.transport_factory.sc_ses', \MauticPlugin\ScMailerSesBundle\Mailer\Transport\ScSesFactory::class)
    ->tag('mailer.transport_factory')
    ->autowire();

    $services->set('sc+ses+api', \MauticPlugin\ScMailerSesBundle\Mailer\Transport\SesTransportExtension::class)
        ->tag('mautic.email.transport_extension', [
            \Mautic\EmailBundle\Model\TransportType::TRANSPORT_ALIAS   => 'mautic.email.config.mailer_transport.ses',
            \Mautic\EmailBundle\Model\TransportType::FIELD_HOST        => false,
            \Mautic\EmailBundle\Model\TransportType::FIELD_PORT        => false,
            \Mautic\EmailBundle\Model\TransportType::FIELD_USER        => true,
            \Mautic\EmailBundle\Model\TransportType::FIELD_PASSWORD    => true,
            \Mautic\EmailBundle\Model\TransportType::TRANSPORT_OPTIONS => MauticPlugin\ScMailerSesBundle\Form\Type\ConfigType::class,
            \Mautic\EmailBundle\Model\TransportType::DSN_CONVERTOR     => MauticPlugin\ScMailerSesBundle\Helper\DsnSesConvertor::class,
        ])
        ->autowire(true);

    $services->set('mautic.email.ses.callback', \MauticPlugin\ScMailerSesBundle\Mailer\Callback\AmazonCallback::class)
        ->autowire();

    $services->load('MauticPlugin\\ScMailerSesBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');
};
