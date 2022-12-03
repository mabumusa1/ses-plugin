<?php
/*
* @copyright   2022 Steer Campaign. All rights reserved
* @author      Steer Campaign <m.abumusa@steercampaign.com>
*
* @link        https://steercampaign.com
*
*/

declare(strict_types=1);

namespace MauticPlugin\ScMailerSesBundle\Mailer\Transport;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\SesV2\Exception\SesV2Exception;
use Aws\SesV2\SesV2Client;
use Mautic\EmailBundle\Mailer\Exception\ConnectionErrorException;
use Mautic\EmailBundle\Mailer\Transport\CallbackTransportInterface;
use Mautic\EmailBundle\Mailer\Transport\TestConnectionInterface;
use Mautic\EmailBundle\Mailer\Transport\TransportExtensionInterface;
use MauticPlugin\ScMailerSesBundle\Mailer\Callback\AmazonCallback;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Transport\Dsn;

class SesTransportExtension implements CallbackTransportInterface, TransportExtensionInterface, TestConnectionInterface
{
    private AmazonCallback $amazonCallback;

    public function __construct(AmazonCallback $amazonCallback)
    {
        $this->amazonCallback = $amazonCallback;
    }

    public function getSupportedSchemes(): array
    {
        return ['ses+api'];
    }

    public function processCallbackRequest(Request $request): void
    {
        $this->amazonCallback->processCallbackRequest($request);
    }

    public function testConnection(array $settings): bool
    {
        // settings array should include username and password
        if ($settings['mailer_user'] == '' || $settings['mailer_password'] == '') {
            throw new ConnectionErrorException('You need to provide username and passwords, passwords are not auto-filled');
        }

        $creds = CredentialProvider::fromCredentials(new Credentials($settings['mailer_user'], $settings['mailer_password']));

        $client = new SesV2Client([
        'version'     => 'latest',
        'region'      => $settings['mailer_option_region'],
        'credentials' => $creds,
    ]);

        try {
            $account = $client->getAccount();
            $emailQuotaRemaining = $account->get('SendQuota')['Max24HourSend'] - $account->get('SendQuota')['SentLast24Hours'];
        } catch (SesV2Exception $exception) {
            throw new ConnectionErrorException($exception->getMessage());
        }

        if (! $account->get('SendingEnabled')) {
            throw new ConnectionErrorException('Your AWS SES is not enabled for sending');
        }

        if ($emailQuotaRemaining <= 0) {
            throw new ConnectionErrorException('Your AWS SES quota is currently exceeded');
        }

        return true;
    }
}
