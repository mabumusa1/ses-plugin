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

    $services->load('MauticPlugin\\ScMailerSesBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->set('mailer.transport_factory.sc_ses', \MauticPlugin\ScMailerSesBundle\Mailer\Transport\ScSesFactory::class)
        ->tag('mailer.transport_factory')
        ->autowire();
};
