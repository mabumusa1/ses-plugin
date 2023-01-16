<?php

namespace MauticPlugin\ScMailerSesBundle\Mailer\Transport;

use Aws\CommandPool;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Ses\Exception\SesException;
use Aws\SesV2\SesV2Client;
use Doctrine\ORM\EntityManager;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Mailer\Transport\AbstractTokenArrayTransport;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use MauticPlugin\ScMailerSesBundle\Entity\SesSetting;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ScSesTransport extends AbstractTokenArrayTransport implements TokenTransportInterface
{
    /**
     * @var LoggerInterface|null
     */
    private $logger;

    private SesV2Client $client;

    private SesSetting $setting;

    /**
     * @var bool
     */
    private $enableTemplate;

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var array<int, string>
     */
    private $templateCache;

    public function __construct(EntityManager $em, EventDispatcherInterface $dispatcher, LoggerInterface $logger, SesV2Client $client, SesSetting $setting, bool $enableTemplate)
    {
        parent::__construct($dispatcher, $logger);

        $this->em      = $em;
        $this->logger  = $logger;
        $this->client  = $client;
        $this->setting = $setting;

        /**
         * Since symfony/mailer is transactional by default, we need to set the max send rate to 1
         * to avoid sending multiple emails at once.
         * We are getting tokinzed emails, so there will be MaxSendRate emails per call
         * Mailer should process tokinzed emails one by one
         * This transport SHOULD NOT RUN IN PARALLEL.
         */
        $this->setMaxPerSecond(1);

        $this->enableTemplate = $enableTemplate;

        if ($this->enableTemplate) {
            /**
             * Create an array of the tempaltes we want to create on SES
             * In case we are sending as templates.
             */
            $this->templateCache = $this->setting->getTemplates();
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
            'sc+ses+api://%s:%s@%s%s',
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
        return $this->client->getCredentials()->wait();
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

            $this->message = $email;

            /**
             * This array will be used to replace
             * metadata in the current message
             * in case there are failures.
             */
            $failures = [];

            /*
            * If there is an attachment, send mail using sendRawEmail method
            * SES does not support sending attachments as bulk emails
            */
            if ($email->getAttachments() || !$this->enableTemplate) {
                $commands      = [];
                foreach ($this->convertMessageToRawPayload() as $payload) {
                    $commands[] = $this->client->getCommand('sendEmail', $payload);
                }

                $pool     = new CommandPool($this->client, $commands, [
                    'concurrency' => $this->setting->getMaxSendRate(),
                    'fulfilled'   => function (Result $result, $iteratorId) {
                    },
                    'rejected' => function (AwsException $reason, $iteratorId) use ($commands, &$failures) {
                        $data = $commands[$iteratorId]->toArray();
                        $failed = Address::create($data['Destination']['ToAddresses'][0]);
                        array_push($failures, $failed->getAddress());
                        $this->logger->debug('Rejected: message to '.implode(',', $data['Destination']['ToAddresses']).' with reason '.$reason->getMessage());
                    },
                ]);
                $promise = $pool->promise();
                $promise->wait();
            } else {
                [$template, $payload] = $this->makeTemplateAndMessagePayload();
                $this->createSesTemplate($template);
                $results  = $this->client->sendBulkEmail($payload)->toArray();
                foreach ($results['BulkEmailEntryResults'] as $i => $result) {
                    if ('SUCCESS' != $result['Status']) {
                        //Save the position of the response, it should match the position of the email in the payload
                        $failures[] = $i;
                    }
                }
            }
            $this->processFailures($failures);
        } catch (SesException $exception) {
            $message = $exception->getAwsErrorMessage() ?: $exception->getMessage();
            $code    = $exception->getStatusCode() ?: $exception->getCode();
            throw new TransportException(sprintf('Unable to send an email: %s (code %s).', $message, $code));
        } catch (\Exception $exception) {
            throw new TransportException(sprintf('Unable to send an email: %s .', $exception->getMessage()));
        }
    }

    /**
     * @param array<string|int, mixed> $failures
     */
    private function processFailures(array $failures): void
    {
        if (empty($failures)) {
            return;
        }
        //Make a copy of the metadata
        $metadata = $this->getMetadata();
        $keys     = array_keys($metadata);
        //Clear the metadata
        $this->message->clearMetadata();

        //Add the metadata for the failed recipients
        foreach ($failures as $failure) {
            try {
                //add the email and metadata of the email
                $this->message->addMetadata($failure, $metadata[$failure]);
            } catch (\Exception $e) {
                //if there is an excpetion, then it is a bulksend, use index
                $this->message->addMetadata($keys[$failure], $metadata[$keys[$failure]]);
            }
        }
        $this->logger->debug('There are partial failures, replacing metadata, and failing the message');
        /*
            The message that failed will be retried with only the failed recipients
            This transport assume that the queue mode is enabled
        */
        $this->throwException('There are  '.count($failures).' partial failures');
    }

    /**
     * @param array<string, mixed> $template
     *
     * @throws \Exception
     *
     * @see https://docs.aws.amazon.com/ses/latest/APIReference/API_CreateTemplate.html
     */
    private function createSesTemplate($template): void
    {
        $templateName = $template['TemplateName'];

        /*
         * reuse an existing template if we have created one
         */
        if (false !== array_search($templateName, $this->templateCache)) {
            $this->logger->debug('Template '.$templateName.' already exists in cache');

            return;
        }

        $this->logger->debug('Creating SES template: '.$templateName);

        try {
            $this->client->createEmailTemplate($template);
        } catch (AwsException $e) {
            switch ($e->getAwsErrorCode()) {
            case 'AlreadyExistsException':
                $this->logger->debug('Exception creating template: '.$templateName.', '.$e->getAwsErrorCode().', '.$e->getAwsErrorMessage().', ignoring');
                break;
            default:
                $this->logger->error('Exception creating template: '.$templateName.', '.$e->getAwsErrorCode().', '.$e->getAwsErrorMessage());
                $this->throwException('Exception creating template: '.$templateName.', '.$e->getAwsErrorCode().', '.$e->getAwsErrorMessage());
        }
        }

        /*
         * store the name of this template so that we can delete it when we are done sending
         */
        $this->templateCache[] = $templateName;

        // always get the latest version
        $setting = $this->em->getRepository(SesSetting::class)->find($this->setting->getId());
        $setting->setTemplates($this->templateCache);
        $this->em->persist($setting);
        $this->em->flush();
    }

    /**
     * Add SES supported headers to the payload.
     *
     * @param array<string, mixed> $payload
     * @param MauticMessage        $sentMessage the message to be sent
     */
    private function addSesHeaders(&$payload, MauticMessage &$sentMessage): void
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
                        $sentMessage->getHeaders()->remove($header->getName());
                        break;
                    case 'X-SES-FEEDBACK-FORWARDNG-EMAIL-ADDRESS-IDENTITYARN':
                        $payload['FeedbackForwardingEmailAddressIdentityArn'] = $header->getBodyAsString();
                        $sentMessage->getHeaders()->remove($header->getName());
                        break;
                    case 'X-SES-FROM-EMAIL-ADDRESS-IDENTITYARN':
                        $payload['FromEmailAddressIdentityArn'] = $header->getBodyAsString();
                        $sentMessage->getHeaders()->remove($header->getName());
                        break;
                    /**
                     * https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-sesv2-2019-09-27.html#sendemail
                     * ListManagementOptions is stopped intentionally because Mautic is managing this.
                     */
                    case 'X-SES-CONFIGURATION-SET':
                        $payload['ConfigurationSetName'] = $header->getBodyAsString();
                        $sentMessage->getHeaders()->remove($header->getName());
                        break;
                }
            }
        }
    }

    /**
     * Convert MauticMessage to JSON payload that works with RAW sends.
     *
     * @return \Generator<array<string, mixed>>
     */
    public function convertMessageToRawPayload(): \Generator
    {
        $metadata      = $this->getMetadata();
        $payload       = [];
        if (empty($metadata)) {
            $sentMessage   = clone $this->message;
            $this->logger->debug('No metadata found, sending email as raw');
            $this->addSesHeaders($payload, $sentMessage);
            $payload = [
            'Content' => [
                'Raw' => [
                    'Data' => $sentMessage->toString(),
                ],
            ],
            'Destination' => [
                'ToAddresses'  => $this->stringifyAddresses($sentMessage->getTo()),
                'CcAddresses'  => $this->stringifyAddresses($sentMessage->getCc()),
                'BccAddresses' => $this->stringifyAddresses($sentMessage->getBcc()),
            ],
        ];
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
                $sentMessage->to(new Address($recipient, $mailData['name'] ?? ''));
                MailHelper::searchReplaceTokens($mauticTokens, $mailData['tokens'], $sentMessage);
                $this->addSesHeaders($payload, $sentMessage);
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
                yield $payload;
                $payload = [];
            }
        }
    }

    /**
     * Create the template payload for the AWS SES API.
     *
     * @throws TransportException
     *
     * @return array<int, array<string, mixed>>
     */
    public function makeTemplateAndMessagePayload(): array
    {
        $metadata      = $this->getMetadata();
        if (empty($metadata)) {
            $this->throwException('Metadata is empty, this message should be sent as a raw email');
        }

        $destinations = [];
        $metadataSet  = reset($metadata);
        $emailId      = $metadataSet['emailId'];
        $tokens       = (!empty($metadataSet['tokens'])) ? $metadataSet['tokens'] : [];
        $mauticTokens = array_keys($tokens);
        $tokenReplace = $amazonTokens = [];

        //Convert Mautic Tokens to Amazon SES tokens
        foreach ($tokens as $search => $token) {
            $tokenKey              = preg_replace('/[^\da-z]/i', '_', trim($search, '{}'));
            $tokenReplace[$search] = '{{'.$tokenKey.'}}';
            $amazonTokens[$search] = $tokenKey;
        }
        MailHelper::searchReplaceTokens($mauticTokens, $tokenReplace, $this->message);

        //Create the template payload
        $md5TemplateName = $this->message->getSubject();
        $template        = [
        'TemplateContent' => [
            'Subject' => $this->message->getSubject(),
        ],
    ];

        if ($this->message->getTextBody()) {
            $template['TemplateContent']['Text'] = $this->message->getTextBody();
            $md5TemplateName .= $this->message->getTextBody();
        }
        if ($this->message->getHtmlBody()) {
            $template['TemplateContent']['Html'] = $this->message->getHtmlBody();
            $md5TemplateName .= $this->message->getTextBody();
        }

        $template['TemplateName'] = 'MauticTemplate-'.$emailId.'-'.md5($md5TemplateName); //unique template name

        foreach ($metadata as $recipient => $mailData) {
            $ReplacementTemplateData = [];
            foreach ($mailData['tokens'] as $token => $tokenData) {
                $ReplacementTemplateData[$amazonTokens[$token]] = $tokenData;
            }
            $destinations[] = [
            'Destination' => [
                'ToAddresses'  => $this->stringifyAddresses([new Address($recipient, $mailData['name'] ?? '')]),
                'CcAddresses'  => $this->stringifyAddresses($this->message->getCc()),
                'BccAddresses' => $this->stringifyAddresses($this->message->getBcc()),
            ],
            'ReplacementEmailContent' => [
                'ReplacementTemplate' => [
                    'ReplacementTemplateData' => json_encode($ReplacementTemplateData),
                ],
            ],
        ];
        }

        $payload = [
        'BulkEmailEntries' => $destinations,
        'DefaultContent'   => [
            'Template' => [
                'TemplateName' => $template['TemplateName'],
                'TemplateData' => json_encode($ReplacementTemplateData),
            ],
        ],
    ];

        $this->addSesHeaders($payload, $this->message);

        return [$template, $payload];
    }
}
