<?php

namespace MauticPlugin\ScMailerSesBundle\Mailer\Transport;

use Aws\CommandPool;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Ses\Exception\SesException;
use Aws\SesV2\SesV2Client;
use bandwidthThrottle\tokenBucket\BlockingConsumer;
use bandwidthThrottle\tokenBucket\Rate;
use bandwidthThrottle\tokenBucket\storage\SingleProcessStorage;
use bandwidthThrottle\tokenBucket\storage\StorageException;
use bandwidthThrottle\tokenBucket\TokenBucket;
use Exception;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Mailer\Transport\AbstractTokenArrayTransport;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class SesTransport extends AbstractTokenArrayTransport implements TokenTransportInterface
{
    /**
     * @var LoggerInterface|null
     */
    private $logger;

    /**
     * @var SesV2Client
     */
    private $client;

    /**
     * @var Credentials
     */
    private $credentials;

    /**
     * @var array<int, string>
     */
    private $templateCache;

    /**
     * @var BlockingConsumer
     */
    private $createTemplateBucketConsumer;

    /**
     * @var BlockingConsumer
     */
    private $sendTemplateBucketConsumer;

    /**
     * @var int
     */
    private $concurrency;

    /**
     * @param callable|null        $handler
     * @param array<string, mixed> $config
     */
    public function __construct(EventDispatcherInterface $dispatcher, LoggerInterface $logger, array $config, $handler = null)
    {
        parent::__construct($dispatcher, $logger);

        $this->logger = $logger;

        $this->client = new SesV2Client([
            'version'     => 'latest',
            'region'      => $config['region'],
            'credentials' => $config['creds'],
            'handler'     => $handler,
        ]);

        $this->templateCache = [];
    }

    public function __destruct()
    {
        if (count($this->templateCache)) {
            $this->logger->debug('Deleting SES templates that were created in this session');
            foreach ($this->templateCache as $templateName) {
                $this->deleteSesTemplate($templateName);
            }
        }
    }

    public function __toString()
    {
        try {
            $credentials = $this->getCredentials();
        } catch (\Exception $exception) {
            $credentials = new Credentials('', '');
        }

        $parameters = http_build_query(['region' => $this->client->getRegion()]);

        return sprintf(
            'ses+api://%s:%s@%s%s',
            urlencode($credentials->getAccessKeyId()),
            urlencode($credentials->getSecretKey()),
            'default',
            ! empty($parameters) ? '?'.$parameters : ''
        );
    }

    /**
     * Initialize the token buckets for throttling.
     *
     * @throws \Exception
     */
    private function initializeThrottles(): void
    {
        try {
            /**
             * SES limits creating templates to approximately one per second.
             */
            $storageCreate = new SingleProcessStorage();
            $rateCreate = new Rate(1, Rate::SECOND);
            $bucketCreate = new TokenBucket(1, $rateCreate, $storageCreate);
            $this->createTemplateBucketConsumer = new BlockingConsumer($bucketCreate);
            $bucketCreate->bootstrap(1);

            /**
             * SES limits sending emails based on requested account-level limits.
             */
            $storageSend = new SingleProcessStorage();
            $rateSend = new Rate($this->concurrency, Rate::SECOND);
            $bucketSend = new TokenBucket($this->concurrency, $rateSend, $storageSend);
            $this->sendTemplateBucketConsumer = new BlockingConsumer($bucketSend);
            $bucketSend->bootstrap($this->concurrency);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('error configuring token buckets: '.$e->getMessage());
            throw new \Exception($e->getMessage());
        } catch (StorageException $e) {
            $this->logger->error('error bootstrapping token buckets: '.$e->getMessage());
            throw new \Exception($e->getMessage());
        } catch (Exception $e) {
            $this->logger->error('error initializing token buckets: '.$e->getMessage());
            throw $e;
        }
    }

    protected function doSend(SentMessage $message): void
    {
        try {
            /**
             * AWS SES has a limit of how many messages can be sent in a 24h time slot. The remaining messages are calculated
             * from the api. The transport will fail when the quota is exceeded.
             */
            $account = $this->client->getAccount();
            $maxSendRate = (int) floor($account->get('SendQuota')['MaxSendRate']);
            $this->concurrency = $maxSendRate;
            $this->setMaxPerSecond($maxSendRate);

            $emailQuotaRemaining = $account->get('SendQuota')['Max24HourSend'] - $account->get('SendQuota')['SentLast24Hours'];
            if ($emailQuotaRemaining <= 0) {
                $this->logger->error('Your AWS SES quota is currently exceeded, used '.$account->get('SendQuota')['SentLast24Hours'].' of '.$account->get('SendQuota')['Max24HourSend']);
                throw new \Exception('Your AWS SES quota is currently exceeded');
            }

            /*
            * initialize throttle token buckets
            */
            $this->initializeThrottles();

            /*
            * Get the original email message.
            * Then count the number of recipients.
            */
            if (! $message->getOriginalMessage() instanceof MauticMessage) {
                throw new \Exception('Message must be an instance of '.MauticMessage::class);
            }
            $email = $message->getOriginalMessage();
            $count = $this->getBatchRecipientCount($email);

            /*
            * If there is an attachment, send mail using sendRawEmail method
            * SES does not support sending attachments as bulk emails
            */
            if ($email->getAttachments()) {
                //It is not a MauticMessge or it has attachments so we need to send it as a raw email
                $this->sendRawOrSimpleEmail($message, true);
            } else {
                if (count($email->getMetadata()) >= $this->getMaxBatchLimit()) {
                    list($template, $request) = $this->generateBulkTemplateAndMessage($message);
                    $this->createSesTemplate($template);
                    $this->sendBulkEmail($count, $request);
                } else {
                    $this->sendRawOrSimpleEmail($message, false);
                }
            }
        } catch (SesException $exception) {
            $message = $exception->getAwsErrorMessage() ?: $exception->getMessage();
            $code = $exception->getStatusCode() ?: $exception->getCode();
            throw new TransportException(sprintf('Unable to send an email: %s (code %s).', $message, $code));
        } catch (\Exception $exception) {
            throw new TransportException(sprintf('Unable to send an email: %s .', $exception->getMessage()));
        }
    }

    public function getBatchRecipientCount(Email $message, $toBeAdded = 1, $type = 'to'): int
    {
        return count($message->getTo()) + count($message->getCc()) + count($message->getBcc()) + $toBeAdded;
    }

    public function getMaxBatchLimit(): int
    {
        return 50;
    }

    /**
     * @return Credentials
     */
    protected function getCredentials()
    {
        if (null === $this->credentials) {
            $this->credentials = $this->client->getCredentials()->wait();
        }

        return $this->credentials;
    }

    protected function sendRawOrSimpleEmail(SentMessage $message, bool $raw = true): bool
    {
        $email = $message->getOriginalMessage();
        $envelope = $message->getEnvelope();

        try {
            $commands = [];
            if ($raw) {
                //@phpstan-ignore-next-line
                foreach ($this->generateEmailPayload($email, $envelope, $raw) as $rawEmail) {
                    $commands[] = $this->client->getCommand('sendEmail', $rawEmail);
                }
            } else {
                //@phpstan-ignore-next-line
                foreach ($this->generateEmailPayload($email, $envelope, $raw) as $rawEmail) {
                    $commands[] = $this->client->getCommand('sendEmail', $rawEmail);
                }
            }
            $pool = new CommandPool($this->client, $commands, [
            'concurrency' => $this->concurrency,
            'fulfilled'   => function (Result $result, $iteratorId) use ($message) {
                $message->setMessageId((string) $result->get('MessageId'));
            },
            'rejected' => function (AwsException $reason, $iteratorId) {
                $this->throwException($reason->getMessage());
            },
        ]);
            $promise = $pool->promise();
            $promise->wait();

            return true;
        } catch (\Exception $e) {
            $this->throwException($e->getMessage());
        }

        return true;
    }

    /**
     * Convert the message to parameters for the sendRawEmail API.
     *
     * @param MauticMessage $email
     */
    protected function generateEmailPayload(Email $email, Envelope $envelope, bool $raw = true): \Generator
    {
        $this->message = $email;
        $metadata = $this->getMetadata();

        $payload = [
            'FromEmailAddress' => $envelope->getSender()->toString(),
            'Destination'      => [
                'ToAddresses'  => $this->stringifyAddresses($email->getTo()),
                'CcAddresses'  => $this->stringifyAddresses($email->getCc()),
                'BccAddresses' => $this->stringifyAddresses($email->getBcc()),
            ],
        ];

        if ($configurationSetHeader = $this->message->getHeaders()->get('X-SES-CONFIGURATION-SET')) {
            $payload['ConfigurationSetName'] = $configurationSetHeader->getBodyAsString();
        }
        if ($sourceArnHeader = $this->message->getHeaders()->get('X-SES-SOURCE-ARN')) {
            $payload['FromEmailAddressIdentityArn'] = $sourceArnHeader->getBodyAsString();
        }
        if ($email->getReturnPath()) {
            $payload['FeedbackForwardingEmailAddress'] = $email->getReturnPath()->toString();
        }

        foreach ($this->message->getHeaders()->all() as $header) {
            if ($header instanceof MetadataHeader) {
                $payload['EmailTags'][] = ['Name' => $header->getKey(), 'Value' => $header->getValue()];
            }
        }

        if (! empty($metadata)) {
            $metadataSet = reset($metadata);
            $tokens = (! empty($metadataSet['tokens'])) ? $metadataSet['tokens'] : [];
            $mauticTokens = array_keys($tokens);
        }

        foreach ($metadata as $recipient => $mailData) {
            $this->replaceTokens($mauticTokens, $mailData['tokens']);
            if ($raw) {
                $payload['Content'] = [
                    'Raw' => [
                        'Data' => $this->message->toString(),
                    ],
                ];
            } else {
                $payload['Content'] = [
                    'Simple' => [
                        'Subject' => [
                            'Data'    => $this->message->getSubject(),
                            'Charset' => 'utf-8',
                        ],
                    ],
                ];
                if ($email->getTextBody()) {
                    $payload['Content']['Simple']['Body']['Text'] = [
                        'Data'    => $this->message->getTextBody(),
                        'Charset' => $this->message->getTextCharset(),
                    ];
                }
                if ($email->getHtmlBody()) {
                    $payload['Content']['Simple']['Body']['Html'] = [
                        'Data'    => $this->message->getHtmlBody(),
                        'Charset' => $this->message->getHtmlCharset(),
                    ];
                }
                if ($emails = $email->getReplyTo()) {
                    $payload['ReplyToAddresses'] = $this->stringifyAddresses($emails);
                }
            }
        }

        yield $payload;
    }

    /**
     * @param string $templateName
     *
     * @return \Aws\Result<string, mixed>
     *
     * @throws \Exception
     *
     * @see https://docs.aws.amazon.com/ses/latest/APIReference/API_DeleteTemplate.html
     */
    private function deleteSesTemplate($templateName)
    {
        $this->logger->debug('Deleting SES template: '.$templateName);

        try {
            return $this->client->deleteEmailTemplate(['TemplateName' => $templateName]);
        } catch (AwsException $e) {
            $this->logger->error('Exception deleting template: '.$templateName.', '.$e->getAwsErrorCode().', '.$e->getAwsErrorMessage());
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Generate the template and the payload to send to SES.
     *
     * @return array<array<string, mixed>>
     */
    private function generateBulkTemplateAndMessage(SentMessage $message): array
    {
        $email = $message->getOriginalMessage();
        if (! $email instanceof MauticMessage) {
            throw new \InvalidArgumentException('The message must be an instance of '.MauticMessage::class);
        }

        $envelope = $message->getEnvelope();
        $this->message = $email;
        $metadata = $this->getMetadata();
        $messageArray = [];
        if (! empty($metadata)) {
            $metadataSet = reset($metadata);
            $emailId = $metadataSet['emailId'];
            $tokens = (! empty($metadataSet['tokens'])) ? $metadataSet['tokens'] : [];
            $mauticTokens = array_keys($tokens);
            $tokenReplace = $amazonTokens = [];
            foreach ($tokens as $search => $token) {
                $tokenKey = preg_replace('/[^\da-z]/i', '_', trim($search, '{}'));
                $tokenReplace[$search] = '{{'.$tokenKey.'}}';
                $amazonTokens[$search] = $tokenKey;
            }
            $this->replaceTokens($mauticTokens, $tokenReplace);
        }

        /*
         * Let's start by creating the template payload
         */
        $template = [
            'TemplateContent' => [
                'Subject' => $this->message->getSubject(),
            ],
        'TemplateName' => 'MauticTemplate-'.$emailId.'-'.md5($email->getSubject().$email->getHtmlBody()), //unique template name
        ];

        if ($email->getTextBody()) {
            $template['TemplateContent']['Text'] = $email->getTextBody();
        }
        if ($email->getHtmlBody()) {
            $template['TemplateContent']['Html'] = $email->getHtmlBody();
        }

        $destinations = [];
        foreach ($metadata as $recipient => $mailData) {
            $ReplacementTemplateData = [];
            foreach ($mailData['tokens'] as $token => $tokenData) {
                $ReplacementTemplateData[$amazonTokens[$token]] = $tokenData;
            }

            $destinations[] = [
                'Destination' => [
                    'ToAddresses'  => [$recipient],
                    'CcAddresses'  => $email->getCc(),
                    'BccAddresses' => $email->getBcc(),
                ],
                'ReplacementEmailContent' => [
                    'ReplacementTemplate' => [
                        'ReplacementTemplateData' => json_encode($ReplacementTemplateData),
                    ],
                ],
            ];
        }

        $request = [
            'BulkEmailEntries' => $destinations,
            'FromEmailAddress' => $envelope->getSender()->toString(),
            'DefaultContent'   => [
                'Template' => [
                    'TemplateName' => $template['TemplateName'],
                    'TemplateData' => json_encode($ReplacementTemplateData),
                ],
            ],
        ];

        if ($configurationSetHeader = $this->message->getHeaders()->get('X-SES-CONFIGURATION-SET')) {
            $request['ConfigurationSetName'] = $configurationSetHeader->getBodyAsString();
        }

        if ($sourceArnHeader = $this->message->getHeaders()->get('X-SES-SOURCE-ARN')) {
            $request['FromEmailAddressIdentityArn'] = $sourceArnHeader->getBodyAsString();
        }
        if ($email->getReturnPath()) {
            $request['FeedbackForwardingEmailAddress'] = $email->getReturnPath()->toString();
        }
        if ($emails = $email->getReplyTo()) {
            $payload['ReplyToAddresses'] = $this->stringifyAddresses($emails);
        }

        return [$template, $request];
    }

    /**
     * @param array<string, mixed> $template
     *
     * @return \Aws\Result<string, mixed>|null
     *
     * @throws \Exception
     *
     * @see https://docs.aws.amazon.com/ses/latest/APIReference/API_CreateTemplate.html
     */
    private function createSesTemplate($template)
    {
        $templateName = $template['TemplateName'];

        $this->logger->debug('Creating SES template: '.$templateName);

        /*
         * reuse an existing template if we have created one
         */
        if (false !== array_search($templateName, $this->templateCache)) {
            $this->logger->debug('Template '.$templateName.' already exists in cache');

            return null;
        }

        /*
         * wait for a throttle token
         */
        $this->createTemplateBucketConsumer->consume(1);

        try {
            $result = $this->client->createEmailTemplate($template);
        } catch (AwsException $e) {
            switch ($e->getAwsErrorCode()) {
                case 'AlreadyExists':
                    $this->logger->debug('Exception creating template: '.$templateName.', '.$e->getAwsErrorCode().', '.$e->getAwsErrorMessage().', ignoring');
                    break;
                default:
                    $this->logger->error('Exception creating template: '.$templateName.', '.$e->getAwsErrorCode().', '.$e->getAwsErrorMessage());
                    throw new \Exception($e->getMessage());
            }
        }

        /*
         * store the name of this template so that we can delete it when we are done sending
         */
        $this->templateCache[] = $templateName;

        return $result;
    }

    /**
     * @param int                  $count   number of recipients for us to consume from the ticket bucket
     * @param array<string, mixed> $request
     *
     * @return \Aws\Result<string, mixed>
     *
     * @throws \Exception
     *
     * @see https://docs.aws.amazon.com/ses/latest/APIReference/API_SendBulkTemplatedEmail.html
     */
    private function sendBulkEmail($count, $request): Result
    {
        $this->logger->debug('Sending SES template: '.$request['DefaultContent']['Template']['TemplateName'].' to '.$count.' recipients');

        // wait for a throttle token
        $this->sendTemplateBucketConsumer->consume($count);

        try {
            return $this->client->sendBulkEmail($request);
        } catch (AwsException $e) {
            $this->logger->error('Exception sending email template: '.$e->getAwsErrorCode().', '.$e->getAwsErrorMessage());
            throw new \Exception($e->getMessage());
        }
    }
}
