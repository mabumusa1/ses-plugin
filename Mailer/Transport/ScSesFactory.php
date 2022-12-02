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
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class ScSesFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) {
            throw new UnsupportedSchemeException($dsn);
        }

        $user     = $dsn->getUser();
        $password = $dsn->getPassword();
        $limit    = (null === $dsn->getOption('limit')) ? 50 : (int) $dsn->getOption('limit');
        $region   = $dsn->getOption('region');

        if (null === $region) {
            throw new \InvalidArgumentException('The "region" option must be set.');
        }

        if (null !== $user || null !== $password) {
            $creds = CredentialProvider::fromCredentials(new Credentials($user, $password));
        } else {
            throw new \InvalidArgumentException('Please provide your AWS credentials');
        }

        $config = [
            'creds'  => $creds,
            'region' => $region,
            'limit'  => $limit,
        ];

        return new SesTransport($this->dispatcher, $this->logger, $config);
    }

    /**
     * @return array<string>
     */
    protected function getSupportedSchemes(): array
    {
        return ['sc+ses+api'];
    }
}
