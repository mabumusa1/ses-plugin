<?php

namespace MauticPlugin\ScMailerSesBundle\Tests\Mailer\Transport;

use Aws\MockHandler;
use Aws\Result;
use Doctrine\ORM\EntityManager;
use MauticPlugin\ScMailerSesBundle\Entity\SesSetting;
use MauticPlugin\ScMailerSesBundle\Mailer\Transport\ScSesFactory;
use MauticPlugin\ScMailerSesBundle\Mailer\Transport\ScSesTransport;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ScSesFactoryFunctionalTest extends KernelTestCase
{
    /**
     * @var MockObject|EventDispatcherInterface
     */
    private $dispatcherMock;

    /**
     * @var MockObject|LoggerInterface
     */
    private $loggerMock;

    /**
     * @var MockObject|EntityManager
     */
    private $emMock;

    protected function setUp(): void
    {
        /**
         * We need to run this command to make the migration
         * we need to have the table in the database.
         */
        $kernel      = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'mautic:plugin:reload',
        ]);

        // You can use NullOutput() if you don't need the output
        $output = new BufferedOutput();
        $application->run($input, $output);

        // return the output, don't use if you used NullOutput()
        $content = $output->fetch();

        $this->dispatcherMock        = $this->createMock(EventDispatcherInterface::class);
        $this->loggerMock            = $this->createMock(LoggerInterface::class);
        $this->emMock                = $kernel->getContainer()
        ->get('doctrine')
        ->getManager();
    }

    // On teardown clear database
    protected function tearDown(): void
    {
        $this->emMock->getConnection()->executeQuery('SET FOREIGN_KEY_CHECKS=0');
        $this->emMock->getConnection()->executeQuery('TRUNCATE TABLE '.MAUTIC_TABLE_PREFIX.'plugin_ses_settings');
        $this->emMock->getConnection()->executeQuery('SET FOREIGN_KEY_CHECKS=1');
    }

    public function getFactory(): ScSesFactory
    {
        return new ScSesFactory($this->emMock, $this->dispatcherMock, $this->loggerMock);
    }

    public function testCreateSettingsNotFound(): void
    {
        $handlerMock = new MockHandler();
        $handlerMock->append(new Result(['SendQuota' => [
            'Max24HourSend'   => 100,
            'MaxSendRate'     => 100,
            'SentLast24Hours' => 10,
          ]]));

        $factory = $this->getFactory();
        $factory::initClient(new Dsn('sc+ses+api', 'default', 'user', 'password', null, ['region' => 'us-east-1', 'enableTemplate']), $handlerMock);
        $factory->create(new Dsn('sc+ses+api', 'default', 'user', 'password', null, ['region' => 'us-east-1', 'enableTemplate']));

        $setting = $this->emMock
        ->getRepository(SesSetting::class)
        ->findOneBy(['accessKey' => 'user']);

        //Assert Record created in the database with right data
        $this->assertEquals(100, $setting->getMaxSendRate());
        //HandlerMock should be empty now
        $this->assertEquals(0, $handlerMock->count());
    }

    public function testCreateSettingsNotFoundWithException(): void
    {
        $handlerMock = new MockHandler();
        $handlerMock->appendException(new \Exception());

        $factory = $this->getFactory();
        $factory::initClient(new Dsn('sc+ses+api', 'default', 'user', 'password', null, ['region' => 'us-east-1', 'enableTemplate']), $handlerMock);
        $factory->create(new Dsn('sc+ses+api', 'default', 'user', 'password', null, ['region' => 'us-east-1', 'enableTemplate']));

        $setting = $this->emMock
        ->getRepository(SesSetting::class)
        ->findOneBy(['accessKey' => 'user']);

        //Assert Record created in the database with right data
        $this->assertEquals(14, $setting->getMaxSendRate());
    }

    public function testCreateSettingsFound(): void
    {
        /**
         * Add Settings.
         */
        $setting = new SesSetting();
        $setting->setAccessKey('user');
        $setting->setMaxSendRate(500);
        $setting->setTemplates([]);
        $this->emMock->persist($setting);
        $this->emMock->flush();

        $handlerMock = new MockHandler();
        $handlerMock->append(new Result(['SendQuota' => [
            'Max24HourSend'   => 100,
            'MaxSendRate'     => 100,
            'SentLast24Hours' => 10,
          ]]));

        $factory = $this->getFactory();
        $factory::initClient(new Dsn('sc+ses+api', 'default', 'user', 'password', null, ['region' => 'us-east-1', 'enableTemplate']), $handlerMock);
        $client    = $factory::getClient();
        $transport = new ScSesTransport($this->emMock, $this->dispatcherMock, $this->loggerMock, $client, $setting, false);
        $this->assertEquals($transport, $factory->create(new Dsn('sc+ses+api', 'default', 'user', 'password', null, ['region' => 'us-east-1', 'enableTemplate' => false])));
        //GetAccount is never called, so the mockshould not clear
        $this->assertEquals(1, $handlerMock->count());
    }
}
