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
use Mautic\CacheBundle\Cache\CacheProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ScSesFactory extends AbstractTransportFactory
{
    private CacheProvider $cacheProvider;
    
    public function __construct(EventDispatcherInterface $dispatcher = null, HttpClientInterface $client = null, LoggerInterface $logger = null, CacheProvider $cacheProvider)
    {
        parent::__construct($dispatcher, $client, $logger);
        $this->cacheProvider     = $cacheProvider;
    }

    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) {
            throw new UnsupportedSchemeException($dsn);
        }

        $user           = $dsn->getUser();
        $password       = $dsn->getPassword();
        $enableTemplate = (null === $dsn->getOption('send_template')) ? true : (bool) $dsn->getOption('send_template');
        $region         = $dsn->getOption('region');
        if (null === $region) {
            throw new \InvalidArgumentException('The "region" option must be set.');
        }

        if (null !== $user || null !== $password) {
            $creds = CredentialProvider::fromCredentials(new Credentials($user, $password));
        } else {
            throw new \InvalidArgumentException('Please provide your AWS credentials');
        }

        $config = [
            'creds'           => $creds,
            'region'          => $region,
            'enableTemplate'  => $enableTemplate,
        ];

        return new SesTransport($this->dispatcher, $this->logger, $this->cacheProvider, $config);
    }

    /**
     * @return array<string>
     */
    protected function getSupportedSchemes(): array
    {
        return ['ses+api'];
    }
}
