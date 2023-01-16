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

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Psr\Log\LoggerInterface;

final class SesHelper
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var SesV2Client
     */
    private $client;

    public function __construct(LoggerInterface $logger, string $user, string $password, string $region, \Countable $handler=null)
    {
        $this->logger = $logger;

        $config = [
            'version'               => 'latest',
            'credentials'           => CredentialProvider::fromCredentials(new Credentials($user, $password)),
            'region'                => $region,
        ];
        if ($handler) {
            $config['handler'] = $handler;
        }
        $this->client = new SesV2Client($config);
    }

    /**
     * Delete cached templates.
     *
     * @param array<string> $templates
     *
     * @return array<string> with failed templates
     */
    public function deleteTemplates(array $templates): array
    {
        $failed = [];

        foreach ($templates as $templateName) {
            try {
                $this->client->deleteEmailTemplate(['TemplateName' => $templateName]);
            } catch (AwsException $e) {
                $this->logger->debug('failed to delete template: '.$templateName.'. Error: '.$e->getMessage());
                $failed[] = $templateName;
            }
        }

        return $failed;
    }
}
