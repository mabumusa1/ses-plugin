<?php

namespace MauticPlugin\ScMailerSesBundle\Tests\Mailer\Transport;

use Aws\Command;
use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Mautic\CacheBundle\Cache\Adapter\FilesystemTagAwareAdapter;
use Mautic\CacheBundle\Cache\CacheProvider;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use MauticPlugin\ScMailerSesBundle\Mailer\Transport\SesTransport;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class SesTransportTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var CacheProvider
     */
    private $cacheProvider;

    /**
     * @var FilesystemTagAwareAdapter
     */
    private $adapter;

    /**
     * @var MockObject|CoreParametersHelper
     */
    private $coreParametersHelper;

    /**
     * @var MockObject|ContainerInterface
     */
    private $container;

    /**
     * @var MockObject|EventDispatcherInterface
     */
    private $dispatcherMock;

    /**
     * @var MockObject|LoggerInterface
     */
    private $loggerMock;

    /**
     * @var array<string, mixed>
     */
    private $configMock = [];

    /**
     * @var MockHandler
     */
    private $handlerMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter               = new FilesystemTagAwareAdapter('ses_test');
        $this->adapter->clear();
        $this->coreParametersHelper  = $this->createMock(CoreParametersHelper::class);
        $this->container             = $this->createMock(ContainerInterface::class);
        $this->cacheProvider         = new CacheProvider($this->coreParametersHelper, $this->container);
        $this->dispatcherMock        = $this->createMock(EventDispatcherInterface::class);
        $this->loggerMock            = $this->createMock(LoggerInterface::class);

        $this->handlerMock = new MockHandler();
        $this->handlerMock->append(new Result(['SendQuota' => [
          'Max24HourSend'   => 100,
          'MaxSendRate'     => 100,
          'SentLast24Hours' => 10,
        ]]));

        $this->configMock  = [
            'region' => 'us-east-1',
            'creds'  => CredentialProvider::fromCredentials(new Credentials('user', 'password')),
        ];

        $this->coreParametersHelper->expects($this->once())
        ->method('get')
        ->with('cache_adapter')
        ->willReturn('foo.bar');

        $this->container->expects($this->once())
        ->method('has')
        ->with('foo.bar')
        ->willReturn(true);

        $this->container->expects($this->once())
        ->method('get')
        ->with('foo.bar')
        ->willReturn($this->adapter);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->adapter->clear();
    }

    /**
     * Send tokinized raw email, mix of success and failure.
     */
    public function testSendTokenizedRawErrorAndSucess(): void
    {
        $this->configMock['enableTemplate'] = false;
        $transport                          = new SesTransport($this->dispatcherMock, $this->loggerMock, $this->cacheProvider, $this->configMock, $this->handlerMock);

        $this->handlerMock->append(new AwsException('foo', new Command('sendEmail')));
        $this->handlerMock->append(new Result(['MessageId' => 'foo2']));
        $this->handlerMock->append(new Result(['MessageId' => 'foo3']));
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unable to send an email: There are  1 partial failures .');

        $transport->send($this->makeTokenizedSentMessage());
    }

    /**
     * Send tokinized raw email, all of three attempts success.
     */
    public function testSendTokenizedRaw(): void
    {
        $this->configMock['enableTemplate'] = false;
        $transport                          = new SesTransport($this->dispatcherMock, $this->loggerMock, $this->cacheProvider, $this->configMock, $this->handlerMock);

        foreach (range(1, 3) as $i) {
            $this->handlerMock->append(new Result(['MessageId' => 'foo'.$i]));
        }

        /**
         * Send a tokinized message, it should be parsed and we should get a raw request
         * We will match our last message.
         */
        $transport->send($this->makeTokenizedSentMessage());
        $cmd = $this->handlerMock->getLastCommand()->toArray();

        $this->assertArrayHasKey('Destination', $cmd);
        $this->assertArrayHasKey('ToAddresses', $cmd['Destination']);
        $this->assertArrayHasKey('CcAddresses', $cmd['Destination']);
        $this->assertArrayHasKey('BccAddresses', $cmd['Destination']);
        $this->assertArrayHasKey('FeedbackForwardingEmailAddress', $cmd);
        $this->assertArrayHasKey('FromEmailAddressIdentityArn', $cmd);
        $this->assertArrayHasKey('ConfigurationSetName', $cmd);
        $this->assertArrayHasKey('ReplyToAddresses', $cmd);
        $this->assertArrayHasKey('EmailTags', $cmd);
        $this->assertArrayHasKey('Content', $cmd);
        $this->assertArrayHasKey('Raw', $cmd['Content']);
        $this->assertArrayHasKey('Data', $cmd['Content']['Raw']);
        $this->assertArrayHasKey('FromEmailAddress', $cmd);
        $this->assertEquals($cmd['FeedbackForwardingEmailAddress'], 1);
        $this->assertEquals($cmd['FeedbackForwardingEmailAddressIdentityArn'], 2);
        $this->assertEquals($cmd['FromEmailAddressIdentityArn'], 3);
        $this->assertEquals($cmd['ConfigurationSetName'], 4);
        $this->assertEquals($cmd['EmailTags'][0]['Name'], 'Color');
        $this->assertEquals($cmd['EmailTags'][0]['Value'], 'blue');
        $this->assertEquals(count($cmd['EmailTags']), 1);
        $this->assertStringContainsString('Subject: test-subject Third Lead', $cmd['Content']['Raw']['Data']);
        $this->assertStringContainsString('test-body Third Lead Unsubscribe3', $cmd['Content']['Raw']['Data']);
        $this->assertStringContainsString('<html><body><p>test-body Third Lead</p><p>Unsubscribe3</p></body></html>', $cmd['Content']['Raw']['Data']);
    }

    /**
     * Send tokinized email, bulk send.
     */
    public function testSendTokenizedBulk(): void
    {
        $this->configMock['enableTemplate'] = true;
        $transport                          = new SesTransport($this->dispatcherMock, $this->loggerMock, $this->cacheProvider, $this->configMock, $this->handlerMock);

        //Second call is createEmailTemplate
        $this->handlerMock->append(new Result([]));

        //Third call is sendBulkEmail
        $this->handlerMock->append(new Result([
        'BulkEmailEntryResults' => [
             [
              'Error'    => '',
              'MessageId'=> '1234',
              'Status'   => 'SUCCESS',
             ],
             [
              'Error'    => 'Message was rejected by Amazon SES.',
              'MessageId'=> '',
              'Status'   => 'MESSAGE_REJECTED',
             ],
             [
              'Error'    => 'Your account has been throttled for sending too many emails. Please contact AWS Support to have the throttling limits for your account adjusted.',
              'MessageId'=> '',
              'Status'   => 'ACCOUNT_THROTTLED',
             ],
        ],
      ]));

        //Fourth call is deleteEmailTemplate
        $this->handlerMock->append(new Result([]));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unable to send an email: sendBulkEmail Exception: There are  2 partial failures .');

        /**
         * Send a tokinized message, it should be parsed and we should get a raw request
         * We will match our last message.
         */
        $transport->send($this->makeTokenizedSentMessage());
    }

    private function makeTokenizedSentMessage(): MauticMessage
    {
        $message = new MauticMessage();
        $message->subject('test-subject {contactfield=firstname}');
        $message->text('test-body {contactfield=firstname} {unsubscribe_text}');
        $message->html('<html><body><p>test-body {contactfield=firstname}</p><p>{unsubscribe_text}</p></body></html>');
        $message->updateLeadIdHash('63ab17be139d6701618833');
        $message->from(new Address('jon@doe.com', 'Jon Doe'));
        $message->to(new Address('success+1@simulator.amazonses.com', 'fname1'));
        $cc = [new Address('success+cc1@simulator.amazonses.com', 'cc1'), new Address('success+cc2@simulator.amazonses.com', 'cc2')];
        $message->cc(...$cc);
        $bcc = [new Address('success+bcc1@simulator.amazonses.com', 'bcc1'), new Address('success+cc2@simulator.amazonses.com', 'bcc2')];
        $message->bcc(...$bcc);
        $message->replyTo(new Address('jon@doe.com', 'Jon Doe'));
        $message->getHeaders()->addTextHeader('Precedence', 'Bulk');
        $message->getHeaders()->addTextHeader('X-EMAIL-ID', '3');
        $message->getHeaders()->addTextHeader('List-Unsubscribe', 'list-unsubscribe-header-value');
        /**
         * Add SES Headers.
         */
        $message->getHeaders()->addTextHeader('X-SES-FEEDBACK-FORWARDNG-EMAIL-ADDRESS', '1');
        $message->getHeaders()->addTextHeader('X-SES-FEEDBACK-FORWARDNG-EMAIL-ADDRESS-IDENTITYARN', '2');
        $message->getHeaders()->addTextHeader('X-SES-FROM-EMAIL-ADDRESS-IDENTITYARN', '3');
        $message->getHeaders()->addTextHeader('X-SES-CONFIGURATION-SET', '4');
        /**
         * SES Supports only Metadata.
         */
        $message->getHeaders()->add(new MetadataHeader('Color', 'blue'));

        $message->addMetadata('success+1@simulator.amazonses.com', [
            'name'        => 'First Lead',
            'leadId'      => '1126',
            'emailId'     => '3',
            'emailName'   => 'test',
            'hashId'      => '63ab17bbc1928037064047',
            'hashIdState' => 'true',
            'source'      => [
              0 => 'email',
              1 => 3,
            ],
            'tokens' => [
              '{contactfield=firstname}'              => 'First Lead',
              '{unsubscribe_text}'                    => 'Unsubscribe1',
            ],
          ]);

        $message->addMetadata('success+2@simulator.amazonses.com', [
            'name'        => 'Second Lead',
            'leadId'      => '610',
            'emailId'     => 3,
            'emailName'   => 'test',
            'hashId'      => '63ab17bc1bf53167810020',
            'hashIdState' => true,
            'source'      => [
              0 => 'email',
              1 => 3,
            ],
            'tokens' => [
              '{contactfield=firstname}'              => 'Second Lead',
              '{unsubscribe_text}'                    => 'Unsubscribe2',
            ],
          ]);

        $message->addMetadata('success+3@simulator.amazonses.com', [
            'name'        => 'Third Lead',
            'leadId'      => '1135',
            'emailId'     => 3,
            'emailName'   => 'test',
            'hashId'      => '63ab17bc2752d691685418',
            'hashIdState' => true,
            'source'      => [
              0 => 'email',
              1 => 3,
            ],
            'tokens' => [
              '{contactfield=firstname}'              => 'Third Lead',
              '{unsubscribe_text}'                    => 'Unsubscribe3',
            ],
          ]);

        return $message;
    }
}
