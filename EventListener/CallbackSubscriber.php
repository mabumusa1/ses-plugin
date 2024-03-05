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
        $this->logger->debug('Receiving webhook from Amazon');

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            $this->logger->error('AmazonCallback: Invalid JSON Payload');
            throw new HttpException(400, 'AmazonCallback: Invalid JSON Payload');
        }

        if (0 !== json_last_error()) {
            throw new HttpException(400, 'AmazonCallback: Invalid JSON Payload');
        }

        $type    = '';
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
    public function processJsonPayload(array $payload, $type): void
    {
        switch ($type) {
            case 'SubscriptionConfirmation':
                // Confirm Amazon SNS subscription by calling back the SubscribeURL from the playload
                try {
                    $response = $this->httpClient->get($payload['SubscribeURL']);
                    if (200 == $response->getStatusCode()) {
                        $this->logger->info('Callback to SubscribeURL from Amazon SNS successfully');
                        break;
                    }

                    $reason = 'HTTP Code '.$response->getStatusCode().', '.$response->getBody();
                } catch (TransferException $e) {
                    $reason = $e->getMessage();
                }

                $this->logger->error('Callback to SubscribeURL from Amazon SNS failed, reason: '.$reason);
                break;
            case 'Notification':
                $message          = json_decode($payload['Message'], true);
                $notificationType = $message['notificationType'];

                if ('Delivery' === $notificationType) {
                    // Handle delivery notification
                } elseif ('Bounce' === $notificationType) {
                    if ('Permanent' == $message['bounce']['bounceType']) {
                        $emailId = null;

                        if (isset($payload['mail']['headers'])) {
                            foreach ($payload['mail']['headers'] as $header) {
                                if ('X-EMAIL-ID' === $header['name']) {
                                    $emailId = $header['value'];
                                }
                            }
                        }
                        // Get bounced recipients in an array
                        $bouncedRecipients = $message['bounce']['bouncedRecipients'];
                        foreach ($bouncedRecipients as $bouncedRecipient) {
                            $bounceCode = array_key_exists('diagnosticCode', $bouncedRecipient) ? $bouncedRecipient['diagnosticCode'] : 'unknown';
                            $this->transportCallback->addFailureByAddress($bouncedRecipient['emailAddress'], $bounceCode, DoNotContact::BOUNCED, $emailId);
                            $this->logger->debug("Mark email '".$bouncedRecipient['emailAddress']."' as bounced, reason: ".$bounceCode);
                        }
                    }
                } elseif ('Complaint' === $notificationType) {
//          $message = json_decode($payload['Message'], true);
                    $emailId = null;
                    if (isset($payload['mail']['headers'])) {
                        foreach ($payload['mail']['headers'] as $header) {
                            if ('X-EMAIL-ID' === $header['name']) {
                                $emailId = $header['value'];
                            }
                        }
                    }
                    // Get bounced recipients in an array
                    $complaintRecipients = $message['complaint']['complainedRecipients'];
                    foreach ($complaintRecipients as $complaintRecipient) {
                        $bounceCode = array_key_exists('complaintFeedbackType', $complaintRecipient) ? $complaintRecipient['complaintFeedbackType'] : 'unknown';
                        $this->transportCallback->addFailureByAddress($complaintRecipient['emailAddress'], $bounceCode, DoNotContact::BOUNCED, $emailId);
                        $this->logger->debug("Mark email '".$complaintRecipient['emailAddress']."' has complained, reason: ".$bounceCode);
                    }
                    break;
                } else {
                    $this->logger->error('Unsupported notification type: '.$notificationType);
                }
                break;
            default:
                // $this->logger->warning("Received SES webhook of type '$payload[Type]' but couldn't understand payload");
                $this->logger->warning('SES webhook payload: '.json_encode($payload));
                break;
        }
    }
}
