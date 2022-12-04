<?php
/*
* @copyright   2022 Steer Campaign. All rights reserved
* @author      Steer Campaign <m.abumusa@steercampaign.com>
*
* @link        https://steercampaign.com
*
*/

declare(strict_types=1);

namespace MauticPlugin\ScMailerSesBundle\Helper;

use Mautic\CoreBundle\Helper\Dsn\Dsn;

class DsnSesConvertor
{
    private const ALLOWED_OPTIONS = [
        'region',
        'send_template',
    ];

    /**
     * Convert DSN to array.
     *
     * @param array<string, string> $parameters
     */
    public static function convertArrayToDsnString(array $parameters): string
    {
        $dsn = new Dsn(
            'ses+api',
            'default',
            $parameters['mailer_user'],
            $parameters['mailer_password'],
            null,
            [
                'region'        => $parameters['mailer_option_region'],
                'send_template' => $parameters['mailer_option_send_template'],
            ]
        );

        $dsnString = $dsn->getScheme().'://';
        if (!empty($dsn->getUser())) {
            $dsnString .= $dsn->getUser();
        }
        if (!empty($dsn->getPassword())) {
            $dsnString .= ':'.$dsn->getPassword();
        }
        if (!empty($dsn->getUser()) || !empty($dsn->getPassword())) {
            $dsnString .= '@';
        }
        $dsnString .= $dsn->getHost();

        $options = [];
        foreach (self::ALLOWED_OPTIONS as $option) {
            if (null !== $dsn->getOption($option)) {
                $options[$option] = $dsn->getOption($option);
            }
        }
        if (!empty($options)) {
            $dsnString .= '?'.http_build_query($options);
        }

        return $dsnString;
    }
}
