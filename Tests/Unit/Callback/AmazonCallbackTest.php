<?php
/*
* @copyright   2022 Steer Campaign. All rights reserved
* @author      Steer Campaign <m.abumusa@steercampaign.com>
*
* @link        https://steercampaign.com
*
*/

declare(strict_types=1);

namespace MauticPlugin\ScMailerSesBundle\Tests\Mailer\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Mautic\EmailBundle\Model\TransportCallback;
use MauticPlugin\ScMailerSesBundle\Mailer\Callback\AmazonCallback;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

class AmazonCallbackTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var MockObject|LoggerInterface
     */
    private $loggerMock;

    /**
     * @var MockObject|Client
     */
    private $httpMock;

    /**
     * @var MockObject|TranslatorInterface
     */
    private $translatorMock;

    /**
     * @var MockObject|TransportCallback
     */
    private $transportCallbackMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loggerMock            = $this->createMock(LoggerInterface::class);
        $this->httpMock              = $this->createMock(Client::class);
        $this->translatorMock        = $this->createMock(TranslatorInterface::class);
        $this->transportCallbackMock = $this->createMock(TransportCallback::class);
    }

    public function testProcessInvalidJsonRequest(): void
    {
        $payload = <<< 'PAYLOAD'
{
   "Type": "Invalid
}
PAYLOAD;

        $amazonCallback = new AmazonCallback($this->loggerMock, $this->httpMock, $this->translatorMock, $this->transportCallbackMock);

        $request = $this->getMockBuilder(Request::class)
       ->disableOriginalConstructor()
       ->getMock();

        $request->expects($this->any())
           ->method('getContent')
           ->will($this->returnValue($payload));

        $this->expectException(HttpException::class);

        $amazonCallback->processCallbackRequest($request);
    }

    public function testProcessValidJsonWithoutTypeRequest(): void
    {
        $payload = <<< 'PAYLOAD'
{
   "Content": "Not Type"
}
PAYLOAD;

        $amazonCallback = new AmazonCallback($this->loggerMock, $this->httpMock, $this->translatorMock, $this->transportCallbackMock);

        $request = $this->getMockBuilder(Request::class)
       ->disableOriginalConstructor()
       ->getMock();

        $request->expects($this->any())
           ->method('getContent')
           ->will($this->returnValue($payload));

        $this->expectException(HttpException::class);

        $amazonCallback->processCallbackRequest($request);
    }

    public function testProcessSubscriptionConfirmationRequest(): void
    {
        $payload = <<< 'PAYLOAD'
{
   "Type" : "SubscriptionConfirmation",
   "MessageId" : "a3466e9f-872a-4438-9cf8-91d282af0f53",
   "Token" : "2336412f37fb687f5d51e6e241d44a2cbcd89f3e7ec51a160fe3cbfc82bc5853b2b75443b051bbeb52c98da19f609e9de0da18c341fe56a51b34f95203cb9bbab9fda0ba97eb5c43b3102911d6a68e05b8023efa4daeb8e217fd1c7325237d53f8e4e95fd3b0217dd13485a8f61f39478a21d55ec0a96ec0f163167053d86c76",
   "TopicArn" : "arn:aws:sns:eu-west-1:918057160339:55hubs-mautic-test",
   "Message" : "You have chosen to subscribe to the topic arn:aws:sns:eu-west-1:918057160339:55hubs-mautic-test. To confirm the subscription, visit the SubscribeURL included in this message.",
   "SubscribeURL" : "https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=arn:aws:sns:eu-west-1:918057160339:55hubs-mautic-test&Token=2336412f37fb687f5d51e6e241d44a2cbcd89f3e7ec51a160fe3cbfc82bc5853b2b75443b051bbeb52c98da19f609e9de0da18c341fe56a51b34f95203cb9bbab9fda0ba97eb5c43b3102911d6a68e05b8023efa4daeb8e217fd1c7325237d53f8e4e95fd3b0217dd13485a8f61f39478a21d55ec0a96ec0f163167053d86c76",
   "Timestamp" : "2016-08-17T07:14:09.912Z",
   "SignatureVersion" : "1",
   "Signature" : "Vzi/S+YKbWA7VfLMPJxiKoIEi61/kH3BHtRMFe3FdMAm6RcJyEUjVZ5CmJCRFywGspHcCP6db3JedeI9yLAKm9fwDDg74PanONzGhcb4ja3e7E7B7auCk7exAVZojrKbY+yEJk91CfoqY4BTp3m3sD2/9o1phj+Dn+hENDSGVRP3zrs6VCuL7KFPYi88kCT/5d3suHDpbINwCAkKkXZWcRtx+Ka7uZdq2AA6MJdedIQ+DscL+7C1htJ/X4LcUiw9KUsweibCbz1mxpZVJ9uLbW5uLmykkBjnp5SecRcYA5vqowGpMq/vyI8RANs9udnn0vnGYFh6GwHXFZbdZtDCsw==",
   "SigningCertURL" : "https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-bb750dd426d95ee9390147a5624348ee.pem"
}
PAYLOAD;

        $amazonCallback = new AmazonCallback($this->loggerMock, $this->httpMock, $this->translatorMock, $this->transportCallbackMock);
        $request        = $this->createMock(Request::class);

        $request->expects($this->any())
           ->method('getContent')
           ->will($this->returnValue($payload));

        // Mock a successful response
        $mockResponse = new Response(200);

        $this->httpMock->expects($this->once())
           ->method('get')
           ->willReturn($mockResponse);

        $amazonCallback->processCallbackRequest($request);
    }

    public function testProcessNotificationBounceRequest(): void
    {
        $payload = <<< 'PAYLOAD'
{
   "Type" : "Notification",
   "MessageId" : "7c2d7069-7db3-53c8-87d0-20476a630fb6",
   "TopicArn" : "arn:aws:sns:eu-west-1:918057160339:55hubs-mautic-test",
   "Message" : "{\"notificationType\":\"Bounce\",\"bounce\":{\"bounceType\":\"Permanent\",\"bounceSubType\":\"General\",\"bouncedRecipients\":[{\"emailAddress\":\"nope@nope.com\",\"action\":\"failed\",\"status\":\"5.1.1\",\"diagnosticCode\":\"smtp; 550 5.1.1 <nope@nope.com>: Recipient address rejected: User unknown in virtual alias table\"}],\"timestamp\":\"2016-08-17T07:43:12.776Z\",\"feedbackId\":\"0102015697743d4c-619f1aa8-763f-4bea-8648-0b3bbdedd1ea-000000\",\"reportingMTA\":\"dsn; a4-24.smtp-out.eu-west-1.amazonses.com\"},\"mail\":{\"timestamp\":\"2016-08-17T07:43:11.000Z\",\"source\":\"admin@55hubs.ch\",\"sourceArn\":\"arn:aws:ses:eu-west-1:918057160339:identity/nope.com\",\"sendingAccountId\":\"918057160339\",\"messageId\":\"010201569774384f-81311784-10dd-48a8-921f-8316c145e64d-000000\",\"destination\":[\"nope@nope.com\"]}}",
   "Timestamp" : "2016-08-17T07:43:12.822Z",
   "SignatureVersion" : "1",
   "Signature" : "GNWnMWfKx1PPDjUstq2Ln13+AJWEK/Qo8YllYC7dGSlPhC5nClop5+vCj0CG2XN7aN41GhsJJ1e+F4IiRxm9v2wwua6BC3mtykrXEi8VeGy2HuetbF9bEeBEPbtbeIyIXJhdPDhbs4anPJwcEiN/toCoANoPWJ3jyVTOaUAxJb2oPTrvmjMxMpVE59sSo7Mz2+pQaUJl3ma0UgAC/lrYghi6n4cwlDTfbbIW+mbV7/d/5YN/tjL9/sD3DOuf+1PpFFTPsOVseZWV8PQ0/MWB2BOrKOKQyF7msLNX5iTkmsvRrbYULPvpbx32LsIxfNVFZJmsnTe2/6EGaAXf3TVPZA==",
   "SigningCertURL" : "https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-bb750dd426d95ee9390147a5624348ee.pem",
   "UnsubscribeURL" : "https://sns.eu-west-1.amazonaws.com/?Action=Unsubscribe&SubscriptionArn=arn:aws:sns:eu-west-1:918057160339:nope:1cddd2a6-bfa8-4eb5-b2b2-a7833eb5db9b"
}
PAYLOAD;

        $amazonCallback = new AmazonCallback($this->loggerMock, $this->httpMock, $this->translatorMock, $this->transportCallbackMock);

        $request = $this->getMockBuilder(Request::class)
       ->disableOriginalConstructor()
       ->getMock();

        $request->expects($this->any())
           ->method('getContent')
           ->will($this->returnValue($payload));

        // Mock a successful response
        $mockResponse       = $this->getMockBuilder(Response::class)->getMock();
        $this->transportCallbackMock->expects($this->once())
           ->method('addFailureByAddress');

        $amazonCallback->processCallbackRequest($request);
    }

    public function testProcessNotificationComplaintRequest(): void
    {
        $payload = <<< 'PAYLOAD'
{
   "Type" : "Notification",
   "MessageId" : "7c2d7069-7db3-53c8-87d0-20476a630fb6",
   "TopicArn" : "arn:aws:sns:eu-west-1:918057160339:55hubs-mautic-test",
   "Message": "{\"notificationType\":\"Complaint\", \"complaint\":{ \"complainedRecipients\":[ { \"emailAddress\":\"richard@example.com\" } ], \"timestamp\":\"2016-01-27T14:59:38.237Z\", \"feedbackId\":\"0000013786031775-fea503bc-7497-49e1-881b-a0379bb037d3-000000\" } }",
   "Timestamp" : "2016-08-17T07:43:12.822Z",
   "SignatureVersion" : "1",
   "Signature" : "GNWnMWfKx1PPDjUstq2Ln13+AJWEK/Qo8YllYC7dGSlPhC5nClop5+vCj0CG2XN7aN41GhsJJ1e+F4IiRxm9v2wwua6BC3mtykrXEi8VeGy2HuetbF9bEeBEPbtbeIyIXJhdPDhbs4anPJwcEiN/toCoANoPWJ3jyVTOaUAxJb2oPTrvmjMxMpVE59sSo7Mz2+pQaUJl3ma0UgAC/lrYghi6n4cwlDTfbbIW+mbV7/d/5YN/tjL9/sD3DOuf+1PpFFTPsOVseZWV8PQ0/MWB2BOrKOKQyF7msLNX5iTkmsvRrbYULPvpbx32LsIxfNVFZJmsnTe2/6EGaAXf3TVPZA==",
   "SigningCertURL" : "https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-bb750dd426d95ee9390147a5624348ee.pem",
   "UnsubscribeURL" : "https://sns.eu-west-1.amazonaws.com/?Action=Unsubscribe&SubscriptionArn=arn:aws:sns:eu-west-1:918057160339:nope:1cddd2a6-bfa8-4eb5-b2b2-a7833eb5db9b"
   }
PAYLOAD;

        $amazonCallback = new AmazonCallback($this->loggerMock, $this->httpMock, $this->translatorMock, $this->transportCallbackMock);

        $request = $this->getMockBuilder(Request::class)
       ->disableOriginalConstructor()
       ->getMock();

        $request->expects($this->any())
           ->method('getContent')
           ->will($this->returnValue($payload));

        // Mock a successful response
        $mockResponse       = $this->getMockBuilder(Response::class)->getMock();

        $this->transportCallbackMock->expects($this->once())
           ->method('addFailureByAddress');

        $amazonCallback->processCallbackRequest($request);
    }

    public function testProcessNotificationComplaintRequestConfigSet(): void
    {
        $payload = <<< 'PAYLOAD'
       {"eventType":"Complaint","complaint":{"complainedRecipients":[{"emailAddress":"recipient@example.com"}],"timestamp":"2017-08-05T00:41:02.669Z","feedbackId":"01000157c44f053b-61b59c11-9236-11e6-8f96-7be8aexample-000000","userAgent":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.90 Safari/537.36","complaintFeedbackType":"abuse","arrivalDate":"2017-08-05T00:41:02.669Z"},"mail":{"timestamp":"2017-08-05T00:40:01.123Z","source":"Sender Name <sender@example.com>","sourceArn":"arn:aws:ses:us-east-1:123456789012:identity/sender@example.com","sendingAccountId":"123456789012","messageId":"EXAMPLE7c191be45-e9aedb9a-02f9-4d12-a87d-dd0099a07f8a-000000","destination":["recipient@example.com"],"headersTruncated":false,"headers":[{"name":"From","value":"Sender Name <sender@example.com>"},{"name":"To","value":"recipient@example.com"},{"name":"Subject","value":"Message sent from Amazon SES"},{"name":"MIME-Version","value":"1.0"},{"name":"Content-Type","value":"multipart/alternative; boundary=\"----=_Part_7298998_679725522.1516840859643\""}],"commonHeaders":{"from":["Sender Name <sender@example.com>"],"to":["recipient@example.com"],"messageId":"EXAMPLE7c191be45-e9aedb9a-02f9-4d12-a87d-dd0099a07f8a-000000","subject":"Message sent from Amazon SES"},"tags":{"ses:configuration-set":["ConfigSet"],"ses:source-ip":["192.0.2.0"],"ses:from-domain":["example.com"],"ses:caller-identity":["ses_user"]}}}
PAYLOAD;

        $amazonCallback = new AmazonCallback($this->loggerMock, $this->httpMock, $this->translatorMock, $this->transportCallbackMock);

        $request = $this->getMockBuilder(Request::class)
       ->disableOriginalConstructor()
       ->getMock();

        $request->expects($this->any())
           ->method('getContent')
           ->will($this->returnValue($payload));

        // Mock a successful response
        $mockResponse       = $this->getMockBuilder(Response::class)->getMock();

        $this->transportCallbackMock->expects($this->once())
           ->method('addFailureByAddress');

        $amazonCallback->processCallbackRequest($request);
    }
}
