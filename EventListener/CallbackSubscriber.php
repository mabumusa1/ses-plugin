<?php

namespace MauticPlugin\ScMailerSesBundle\EventListener;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use MauticPlugin\ScMailerSesBundle\CallbackMessages;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\Translation\TranslatorInterface;

class CallbackSubscriber implements EventSubscriberInterface
{
    private LoggerInterface $logger;

    private Client $httpClient;

    private TranslatorInterface $translator;

    private TransportCallback $transportCallback;

    private CoreParametersHelper $coreParametersHelper;

    public function __construct(
        LoggerInterface $logger,
        Client $httpClient,
        TranslatorInterface $translator,
        TransportCallback $transportCallback,
        CoreParametersHelper $coreParametersHelper
    ) {
        $this->logger               = $logger;
        $this->httpClient           = $httpClient;
        $this->translator           = $translator;
        $this->transportCallback    = $transportCallback;
        $this->coreParametersHelper = $coreParametersHelper;
    }

    private function createErrorResponse($message, $statusCode=Response::HTTP_OK)
    {
        return new Response(
            json_encode([
                'message' => $message,
                'success' => false,
            ]),
            $statusCode,
            ['content-type' => 'application/json']
        );
    }

    private function createSuccessResponse($message, $statusCode=Response::HTTP_BAD_REQUEST)
    {
        return new Response(
            json_encode([
                'message' => $message,
                'success' => true,
            ]),
            $statusCode,
            ['content-type' => 'application/json']
        );
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
    public function processCallbackRequest(TransportWebhookEvent $event): void
    {
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));
        if ('ses+api' !== $dsn->getScheme()) {
            return;
        }

        $this->logger->debug('Start processCallbackRequest - webhook from Amazon');
        try {
            $request = $event->getRequest();
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            $this->logger->error('AmazonCallback: Invalid JSON Payload');
            $event->setResponse(
                $this->createErrorResponse(
                    CallbackMessages::INVALID_JSON_PAYLOAD_ERROR
                )
            );

            return;
        }

        if (0 !== json_last_error()) {
            $event->setResponse(
                $this->createErrorResponse(
                    CallbackMessages::INVALID_JSON_PAYLOAD_ERROR
                )
            );

            return;
        }

        $type    = '';
        if (array_key_exists('Type', $payload)) {
            $type = $payload['Type'];
        } elseif (array_key_exists('eventType', $payload)) {
            $type = $payload['eventType'];
        } else {
            $event->setResponse(
                $this->createErrorResponse(
                    CallbackMessages::TYPE_MISSING_ERROR
                )
            );

            return;
        }

        [$hasError, $message] = $this->processJsonPayload($payload, $type);
        if ($hasError) {
            $eventResponse = $this->createErrorResponse($message);
        } else {
            $eventResponse = $this->createSuccessResponse($message);
        }

        $this->logger->debug('End processCallbackRequest - webhook from Amazon');
        $event->setResponse($eventResponse);
    }

    /**
     * Process json request from Amazon SES.
     *
     * http://docs.aws.amazon.com/ses/latest/DeveloperGuide/best-practices-bounces-complaints.html
     *
     * @param array<string, mixed> $payload from Amazon SES
     */
    public function processJsonPayload(array $payload, $type): array
    {
        $typeFound = false;
        $hasError  = false;
        $message   = 'PROCESSED';
        switch ($type) {
            case 'SubscriptionConfirmation':
                $typeFound = true;

                $reason = null;

                // Confirm Amazon SNS subscription by calling back the SubscribeURL from the playload
                try {
                    $response = $this->httpClient->get($payload['SubscribeURL']);
                    if (200 == $response->getStatusCode()) {
                        $this->logger->info('Callback to SubscribeURL from Amazon SNS successfully');
                        break;
                    } else {
                        $reason = 'HTTP Code '.$response->getStatusCode().', '.$response->getBody();
                    }
                } catch (TransferException $e) {
                    $reason = $e->getMessage();
                }

                if (null !== $reason) {
                    $this->logger->error(
                        'Callback to SubscribeURL from Amazon SNS failed, reason: ',
                        ['reason' => $reason]
                    );

                    $hasError = true;
                    $message  = CallbackMessages::UNSUBSCRIBE_ERROR;
                }

                break;

            case 'Notification':
                $typeFound = true;

                try {
                    $message = json_decode($payload['Message'], true, 512, JSON_THROW_ON_ERROR);
                    $this->processJsonPayload($message, $message['notificationType']);
                } catch (\Exception $e) {
                    $this->logger->error('AmazonCallback: Invalid Notification JSON Payload');

                    $hasError = true;
                    $message  = CallbackMessages::INVALID_JSON_PAYLOAD_NOTIFICATION_ERROR;
                }

                break;

            case 'Delivery':
                // Nothing more to do here.
                $typeFound = true;

                break;

            case 'Complaint':
                $typeFound = true;

                $emailId = null;
                if (isset($payload['mail']['headers'])) {
                    foreach ($payload['mail']['headers'] as $header) {
                        if ('X-EMAIL-ID' === strtoupper($header['name'])) {
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

            case 'Bounce':
                $typeFound = true;

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
                break;

            default:
                $this->logger->warning(
                    'SES webhook payload, not processed due to unknown type.',
                    ['Type' => $payload['Type'], 'payload' => json_encode($payload)]
                );
                break;
        }

        if (!$typeFound) {
            $message = sprintf(
                CallbackMessages::UNKNOWN_TYPE_WARNING,
                $type
            );
        }

        return [
            'hasError' => $hasError,
            'message'  => $message,
        ];
    }
}
