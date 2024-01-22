<?php

namespace MauticPlugin\ScMailerSesBundle\EventListener;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\EmailBundle\MonitoredEmail\Processor\Bounce\Definition\Type;
use Mautic\LeadBundle\Entity\DoNotContact;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

class CallbackSubscriber implements EventSubscriberInterface
{
    private LoggerInterface $logger;

    private Client $httpClient;

    private TranslatorInterface $translator;

    private TransportCallback $transportCallback;

    public function __construct(
        LoggerInterface $logger,
        Client $httpClient,
        TranslatorInterface $translator,
        TransportCallback $transportCallback
    ) {
        $this->logger            = $logger;
        $this->httpClient        = $httpClient;
        $this->translator        = $translator;
        $this->transportCallback = $transportCallback;
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => ['processCallbackRequest', 0],
        ];
    }

    /**
     * Handle bounces & complaints from Amazon.
     */
    public function processCallbackRequest(Request $request): void
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            $this->logger->error('AmazonCallback: Invalid JSON Payload');
            throw new HttpException(400, 'AmazonCallback: Invalid JSON Payload');
        }

        $type    = '';

        if (0 !== json_last_error()) {
            throw new HttpException(400, 'AmazonCallback: Invalid JSON Payload');
        }

        if (array_key_exists('Type', $payload)) {
            $type = $payload['Type'];
        } elseif (array_key_exists('eventType', $payload)) {
            $type = $payload['eventType'];
        } else {
            throw new HttpException(400, "Key 'Type' not found in payload");
        }

        $this->processJsonPayload($payload, $type);
    }

    /**
     * Process json request from Amazon SES.
     *
     * http://docs.aws.amazon.com/ses/latest/DeveloperGuide/best-practices-bounces-complaints.html
     *
     * @param array<string, mixed> $payload from Amazon SES
     */
    public function processJsonPayload(array $payload, string $type): void
    {
        switch ($type) {
            case 'SubscriptionConfirmation':
                // Confirm Amazon SNS subscription by calling back the SubscribeURL from the playload
                try {
                    $response = $this->httpClient->get($payload['SubscribeURL']);
                    if (200 == $response->getStatusCode()) {
                        $this->logger->info('Callback to SubscribeURL from Amazon SNS successfully');
                    }
                } catch (TransferException $e) {
                    $this->logger->error('Callback to SubscribeURL from Amazon SNS failed, reason: '.$e->getMessage());
                }
                break;

            case 'Notification':
                try {
                    $message = json_decode($payload['Message'], true, 512, JSON_THROW_ON_ERROR);
                } catch (\Exception $e) {
                    $this->logger->error('AmazonCallback: Invalid Notification JSON Payload');
                    throw new HttpException(400, 'AmazonCallback: Invalid Notification JSON Payload');
                }

                $this->processJsonPayload($message, $message['notificationType']);
                break;
            case 'Complaint':
                foreach ($payload['complaint']['complainedRecipients'] as $complainedRecipient) {
                    $reason = null;
                    if (isset($payload['complaint']['complaintFeedbackType'])) {
                        // http://docs.aws.amazon.com/ses/latest/DeveloperGuide/notification-contents.html#complaint-object
                        switch ($payload['complaint']['complaintFeedbackType']) {
                            case 'abuse':
                                $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.abuse');
                                break;
                            case 'auth-failure':
                                $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.auth_failure');
                                break;
                            case 'fraud':
                                $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.fraud');
                                break;
                            case 'not-spam':
                                $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.not_spam');
                                break;
                            case 'other':
                                $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.other');
                                break;
                            case 'virus':
                                $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.virus');
                                break;
                            default:
                                $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.unknown');
                                break;
                        }
                    }

                    if (null === $reason) {
                        if (empty($payload['complaint']['complaintSubType'])) {
                            $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.unknown');
                        } else {
                            $reason = $payload['complaint']['complaintSubType'];
                        }
                    }

                    $emailId = null;

                    if (isset($payload['mail']['headers'])) {
                        foreach ($payload['mail']['headers'] as $header) {
                            if ('X-EMAIL-ID' === $header['name']) {
                                $emailId = $header['value'];
                            }
                        }
                    }

                    $address = Address::create($complainedRecipient['emailAddress']);
                    $this->transportCallback->addFailureByAddress($address->getAddress(), $reason, DoNotContact::UNSUBSCRIBED, $emailId);

                    $this->logger->debug("Unsubscribe email '".$address->getAddress()."'");
                }

                break;
            case 'Bounce':
                if ('Permanent' == $payload['bounce']['bounceType']) {
                    $emailId = null;

                    if (isset($payload['mail']['headers'])) {
                        foreach ($payload['mail']['headers'] as $header) {
                            if ('X-EMAIL-ID' === $header['name']) {
                                $emailId = $header['value'];
                            }
                        }
                    }

                    // Get bounced recipients in an array
                    $bouncedRecipients = $payload['bounce']['bouncedRecipients'];
                    foreach ($bouncedRecipients as $bouncedRecipient) {
                        $bounceCode =  array_key_exists('diagnosticCode', $bouncedRecipient) ? $bouncedRecipient['diagnosticCode'] : 'unknown';
                        $bounceCode .= ' AWS bounce type: '.$payload['bounce']['bounceSubType'];
                        $address = Address::create($bouncedRecipient['emailAddress']);
                        $this->transportCallback->addFailureByAddress($address->getAddress(), $bounceCode, DoNotContact::BOUNCED, $emailId);
                        $this->logger->debug("Mark email '".$bouncedRecipient['emailAddress']."' as bounced, reason: ".$bounceCode);
                    }
                }
                break;
            default:
                $this->logger->warning('Received SES webhook of type '.$payload['Type']." but couldn't understand payload");
                $this->logger->debug('SES webhook payload: '.json_encode($payload));
                throw new HttpException(400, "Received SES webhook of type '$payload[Type]' but couldn't understand payload");
        }
    }
}
