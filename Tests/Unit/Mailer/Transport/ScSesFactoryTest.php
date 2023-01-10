<?php

namespace MauticPlugin\ScMailerSesBundle\Tests\Mailer\Transport;

use Doctrine\ORM\EntityManager;
use MauticPlugin\ScMailerSesBundle\Mailer\Transport\ScSesFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ScSesFactoryTest extends \PHPUnit\Framework\TestCase
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
        parent::setUp();
        $this->dispatcherMock        = $this->createMock(EventDispatcherInterface::class);
        $this->loggerMock            = $this->createMock(LoggerInterface::class);
        $this->emMock                = $this->createMock(EntityManager::class);
    }

    public function getFactory(): ScSesFactory
    {
        return new ScSesFactory($this->emMock, $this->dispatcherMock, $this->loggerMock);
    }

    /**
     * @dataProvider supportsProvider
     */
    public function testSupports(Dsn $dsn, bool $supports): void
    {
        $factory = $this->getFactory();

        $this->assertSame($supports, $factory->supports($dsn));
    }

    /**
     * @return iterable<mixed>
     */
    public function supportsProvider(): iterable
    {
        yield [
            new Dsn('sc+ses+api', 'default'),
            true,
        ];
    }

    /**
     * @dataProvider unsupportedSchemeProvider
     */
    public function testUnsupportedSchemeException(Dsn $dsn, string $message = null): void
    {
        $factory = $this->getFactory();

        $this->expectException(UnsupportedSchemeException::class);

        if (null !== $message) {
            $this->expectExceptionMessage($message);
        }

        $factory->create($dsn);
    }

    /**
     * @return iterable<mixed>
     */
    public function unsupportedSchemeProvider(): iterable
    {
        yield [
            new Dsn('sc+ses+foo', 'default', 'user', 'pass'),
            'The "sc+ses+foo" scheme is not supported.',
        ];
    }

    /**
     * @dataProvider incompleteDsnProvider
     */
    public function testIncompleteDsnException(Dsn $dsn): void
    {
        $factory = $this->getFactory();

        $this->expectException(IncompleteDsnException::class);
        $factory->create($dsn);
    }

    /**
     * @return iterable<mixed>
     */
    public function incompleteDsnProvider(): iterable
    {
        yield [new Dsn('sc+ses+api', 'default', 'user')];
    }
}
