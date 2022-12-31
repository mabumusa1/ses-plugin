<?php

namespace MauticPlugin\ScMailerSesBundle\Mailer\Transport;

use Aws\Api\ApiProvider;
use Aws\Api\Service;
use Aws\Api\Validator;
use Aws\CommandPool;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Ses\Exception\SesException;
use Aws\SesV2\SesV2Client;
use Mautic\CacheBundle\Cache\CacheProvider;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Mailer\Transport\AbstractTokenArrayTransport;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\MessageConverter;


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
     * @var bool
     */
    private $enableTemplate;

    /**
     * @var array<int, string>
     */
    private $templateCache;


    private CacheProvider $cacheProvider;

    private Psr16Cache $cache;

    /**
     * @param callable|null        $handler
     * @param array<string, mixed> $config
     */
    public function __construct(EventDispatcherInterface $dispatcher, LoggerInterface $logger, CacheProvider $cacheProvider, array $config, $handler = null)
    {
        parent::__construct($dispatcher, $logger);

        $this->logger = $logger;

        $this->client = new SesV2Client([
            'version'     => 'latest',
            'region'      => $config['region'],
            'credentials' => $config['creds'],
            'handler'     => $handler,
        ]);

        $this->enableTemplate = $config['enableTemplate'];

        $this->cacheProvider = $cacheProvider;
        $this->cache         = $this->cacheProvider->getSimpleCache();

        if ($this->enableTemplate) {
            /**
             * Create an array of the tempaltes we want to create on SES
             * In case we are sending as templates.
             */
            if (!$this->cache->has('template_cache')) {
                $this->templateCache = [];
            } else {
                $this->templateCache = $this->cache->get('template_cache');
            }
        }

        if (!$this->cache->has('max_send_rate')) {
            /**
             * AWS SES has a limit of how many messages can be sent in a 24h time slot. The remaining messages are calculated
             * from the api. The transport will fail when the quota is exceeded.
             */
            $account           = $this->client->getAccount();
            $maxSendRate       = (int) floor($account->get('SendQuota')['MaxSendRate']);

            /**
             * Since symfony/mailer is transactional by default, we need to set the max send rate to 1
             * to avoid sending multiple emails at once.
             * We are getting tokinzed emails, so there will be MaxSendRate emails per call
             * Mailer should process tokinzed emails one by one
             * This transport SHOULD NOT RUN IN PARALLEL.
             */
            $this->setMaxPerSecond(1);

            $emailQuotaRemaining = $account->get('SendQuota')['Max24HourSend'] - $account->get('SendQuota')['SentLast24Hours'];
            if ($emailQuotaRemaining <= 0) {
                $this->logger->error('Your AWS SES quota is currently exceeded, used '.$account->get('SendQuota')['SentLast24Hours'].' of '.$account->get('SendQuota')['Max24HourSend']);
                throw new \Exception('Your AWS SES quota is currently exceeded');
            }
            $this->cache->set('max_send_rate', $maxSendRate, 86400);
        }
    }

    public function __destruct()
    {
        if ($this->enableTemplate) {
            if (count($this->templateCache)) {
                $this->logger->debug('Deleting SES templates that were created in this session');
                foreach ($this->templateCache as $templateName) {
                    $this->deleteSesTemplate($templateName);
                }
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

        $parameters = http_build_query(['region' => $this->client->getRegion(), 'enableTemplate' => $this->enableTemplate]);

        return sprintf(
            'ses+api://%s:%s@%s%s',
            urlencode($credentials->getAccessKeyId()),
            urlencode($credentials->getSecretKey()),
            'default',
            !empty($parameters) ? '?'.$parameters : ''
        );
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

    protected function doSend(SentMessage $message): void
    {
        try {
            /*
            * Get the original email message.
            */
            $email = $message->getOriginalMessage();
            if (!$email instanceof MauticMessage) {
                throw new \Exception('Message must be an instance of '.MauticMessage::class);
            }

            /*
            * If there is an attachment, send mail using sendRawEmail method
            * SES does not support sending attachments as bulk emails
            */
            if ($email->getAttachments() || !$this->enableTemplate) {
                //It is not a MauticMessge or it has attachments so we need to send it as a raw email
                $this->sendRaw($message);
            } else {
                //Send Templates
            }
        } catch (SesException $exception) {
            $message = $exception->getAwsErrorMessage() ?: $exception->getMessage();
            $code    = $exception->getStatusCode() ?: $exception->getCode();
            throw new TransportException(sprintf('Unable to send an email: %s (code %s).', $message, $code));
        } catch (\Exception $exception) {
            throw new TransportException(sprintf('Unable to send an email: %s .', $exception->getMessage()));
        }
    }

    /**
     * @throws \Exception
     */
    protected function sendRaw(SentMessage $message): bool
    {
        if($message->getOriginalMessage() instanceof MauticMessage) {
            $this->message = $message->getOriginalMessage();
        } else {
            $this->throwException('Message must be an instance of '.MauticMessage::class);
        }

        $commands      = [];
        foreach ($this->makeJsonPayload() as $rawEmail) {
            $commands[] = $this->client->getCommand('sendEmail', $rawEmail);
        }

        // Initializing command validator.
        $validator = new Validator();
        // Specifying SES API to use in validation.
        $api = new Service(
            ApiProvider::resolve(
                ApiProvider::defaultProvider(),
                'api',
                'sesv2',
                '2019-09-27'
            ),
            ApiProvider::defaultProvider()
        );

        // Initializing array to add commands that pass validation.
        $validCommands = [];

        // Iterating through command list to validate each command.
        foreach ($commands as $command) {
            $operation = $api->getOperation($command->getName());
            try {
                $validator->validate(
                    $command->getName(),
                    $operation->getInput(),
                    $command->toArray()
                );
                array_push($validCommands, $command);
            } catch (\Exception $e) {
                $data = $command->toArray();
                $this->logger->debug('Command to Adresses '.implode(',', $data['Destination']['ToAddresses']).' is invalid, issue '.$e->getMessage());
            }
        }

        /**
         * This array will be used to replace
         * metadata in the current message
         * in case there are failures.
         */
        $failures = [];

        try {
            $pool = new CommandPool($this->client, $validCommands, [
                'concurrency' => $this->cache->get('max_send_rate'),
                'fulfilled'   => function (Result $result, $iteratorId) {
                    $this->logger->debug('Fulfilled: with SES ID '.$result['MessageId']);
                },
                'rejected' => function (AwsException $reason, $iteratorId) use ($validCommands, &$failures) {
                    $data = $validCommands[$iteratorId]->toArray();
                    $failed = Address::create($data['Destination']['ToAddresses'][0]);
                    array_push($failures, $failed->getAddress());
                    $this->logger->debug('Rejected: message to '.implode(',', $data['Destination']['ToAddresses']).' with reason '.$reason->getMessage());
                },
            ]);
            $promise = $pool->promise();
            $promise->wait();
            if (!empty($failures)) {
                //Make a copy of the metadata
                $metadata = $this->message->getMetadata();
                //Clear the metadata
                $this->message->clearMetadata();
                //Add the metadata for the failed recipients
                foreach ($failures as $failure) {
                    $this->message->addMetadata($failure, $metadata[$failure]);
                }
                $this->logger->debug('There are partial failures, replacing metadata, and failing the message');
                /*
                    The message that failed will be retried with only the failed recipients
                    This transport assume that the queue mode is enabled
                */
                $this->throwException('There are  '.count($failures).' partial failures');
            }

            return true;
        } catch (\Exception $e) {
            $this->throwException($e->getMessage());

            return false;
        }
    }

    /**
     * Convert the message to parameters for SES API.
     */
    protected function makeJsonPayload(): \Generator
    {
        $metadata      = $this->getMetadata();
        $payload       = [];
        if (empty($metadata)) {
            $sentMessage   = clone $this->message;
            $this->logger->debug('No metadata found, sending email as raw');
            $payload = [
                'Content' => [
                    'Raw' => [
                        'Data' => $sentMessage->toString(),
                    ],
                ],
                'Destination' => [
                    'ToAddresses' => $this->stringifyAddresses($sentMessage->getTo()),
                ],
                'FromEmailAddress' => $sentMessage->getFrom()[0]->getAddress(),
            ];
            $this->addSesHeaders($payload, $sentMessage);
            yield $payload;
            $payload = [];
        } else {
            /**
             * This message is a tokenzied message, SES API does not support tokens in Raw Emails
             * We need to create a new message for each recipient.
             */
            $metadataSet  = reset($metadata);
            $tokens       = (!empty($metadataSet['tokens'])) ? $metadataSet['tokens'] : [];
            $mauticTokens = array_keys($tokens);
            foreach ($metadata as $recipient => $mailData) {
                $sentMessage   = clone $this->message;
                $sentMessage->clearMetadata();
                $sentMessage->updateLeadIdHash($mailData['hashId']);
                $sentMessage->to(new Address($recipient, $mailData['name']));
                MailHelper::searchReplaceTokens($mauticTokens, $mailData['tokens'], $sentMessage);
                $payload['Destination']      = [
                    'ToAddresses'  => $this->stringifyAddresses($sentMessage->getTo()),
                    'CcAddresses'  => $this->stringifyAddresses($sentMessage->getCc()),
                    'BccAddresses' => $this->stringifyAddresses($sentMessage->getBcc()),
                ];
                $payload['Content'] = [
                    'Raw' => [
                        'Data' => $sentMessage->toString(),
                    ],
                ];
                $this->addSesHeaders($payload, $sentMessage);
                yield $payload;
                $payload = [];
            }
        }
    }

    /**
     * Add SES supported headers to the payload.
     *
     * @param   array<string, mixed>  $payload      [$payload description]
     * @param   MauticMessage  $sentMessage  the message to be sent
     *
     * @return  void
     */
    private function addSesHeaders(&$payload, $sentMessage): void
    {
        $payload['FromEmailAddress'] = $sentMessage->getFrom()[0]->getAddress();
        $payload['ReplyToAddresses'] =  $this->stringifyAddresses($sentMessage->getReplyTo());

        foreach ($sentMessage->getHeaders()->all() as $header) {
            if ($header instanceof MetadataHeader) {
                $payload['EmailTags'][] = ['Name' => $header->getKey(), 'Value' => $header->getValue()];
            } else {
                switch ($header->getName()) {
                    case 'X-SES-FEEDBACK-FORWARDNG-EMAIL-ADDRESS':
                        $payload['FeedbackForwardingEmailAddress'] = $header->getBodyAsString();
                        break;
                    case 'X-SES-FEEDBACK-FORWARDNG-EMAIL-ADDRESS-IDENTITYARN':
                        $payload['FeedbackForwardingEmailAddressIdentityArn'] = $header->getBodyAsString();
                        break;
                    case 'X-SES-FROM-EMAIL-ADDRESS-IDENTITYARN':
                        $payload['FromEmailAddressIdentityArn'] = $header->getBodyAsString();
                        break;
                    /**
                     * https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-sesv2-2019-09-27.html#sendemail
                     * ListManagementOptions is stopped intentionally because Mautic is managing this.
                     */
                    case 'X-SES-CONFIGURATION-SET':
                        $payload['ConfigurationSetName'] = $header->getBodyAsString();
                        break;
                }
            }
        }
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
}
