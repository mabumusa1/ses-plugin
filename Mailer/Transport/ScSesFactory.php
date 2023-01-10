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
use Aws\SesV2\SesV2Client;
use Doctrine\ORM\EntityManager;
use MauticPlugin\ScMailerSesBundle\Entity\SesSetting;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ScSesFactory implements TransportFactoryInterface
{
    /**
     * @var EventDispatcherInterface|null
     */
    private $dispatcher;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    /**
     * @var EntityManager
     */
    private $em;

    private static SesV2Client $client;

    public function __construct(EntityManager $em, EventDispatcherInterface $dispatcher = null, LoggerInterface $logger = null)
    {
        $this->dispatcher = $dispatcher;
        $this->logger     = $logger;
        $this->em         = $em;
    }

    /**
     * Init AWS Client.
     *
     * @param \Countable $handler
     */
    public static function initClient(Dsn $dsn, \Countable $handler=null): void
    {
        $user = $dsn->getUser();
        if (null === $user) {
            throw new IncompleteDsnException('User is not set.');
        }

        $password = $dsn->getPassword();
        if (null === $password) {
            throw new IncompleteDsnException('Password is not set.');
        }

        $region = $dsn->getOption('region');
        if (null === $region) {
            throw new IncompleteDsnException('Region is not set.');
        }

        if (!isset(self::$client)) {
            $config = [
                'version'               => 'latest',
                'credentials'           => CredentialProvider::fromCredentials(new Credentials($user, $password)),
                'region'                => $region,
            ];

            if ($handler) {
                $config['handler'] = $handler;
            }

            /**
             * The client is singleton, so we need to check if the client is already created.
             */
            self::$client = new SesV2Client($config);
        }
    }

    public static function getClient(): SesV2Client
    {
        if (!isset(self::$client)) {
            throw new IncompleteDsnException('SesV2Client is not init yet, please init before using it.');
        }

        return self::$client;
    }

    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) {
            throw new UnsupportedSchemeException($dsn);
        }

        self::initClient($dsn);
        $client  = self::getClient();
        $setting = $this->em->getRepository(SesSetting::class)->findOneBy(['accessKey' => $dsn->getUser()]);

        /**
         * This is the first time the user is using the plugin or the key.
         */
        if (!$setting) {
            $setting = new SesSetting();
            $setting->setAccessKey($dsn->getUser());
            $setting->setTemplates([]);
            try {
                $account           = $client->getAccount();
                $maxSendRate       = (int) floor($account->get('SendQuota')['MaxSendRate']);
                $setting->setMaxSendRate($maxSendRate);
            } catch (\Exception $e) {
                $this->logger->debug($e->getMessage());
                $setting->setMaxSendRate(14);
            }

            $this->em->persist($setting);
            $this->em->flush();
        }

        $enableTemplate = (null === $dsn->getOption('enableTemplate')) ? false : (bool) $dsn->getOption('enableTemplate');

        return new ScSesTransport($this->em, $this->dispatcher, $this->logger, $client, $setting, $enableTemplate);
    }

    public function supports(Dsn $dsn): bool
    {
        return \in_array($dsn->getScheme(), $this->getSupportedSchemes());
    }

    /**
     * @return array<string>
     */
    private function getSupportedSchemes(): array
    {
        return ['sc+ses+api'];
    }
}
