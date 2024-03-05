<?php

namespace MauticPlugin\ScMailerSesBundle;

final class CallbackMessages
{
    public const INVALID_JSON_PAYLOAD_ERROR = 'MauticAmazonCallback: Invalid JSON Payload.';

    public const TYPE_MISSING_ERROR = 'Key Type not found in payload.';

    public const UNSUBSCRIBE_ERROR = 'Callback to SubscribeURL from Amazon SNS failed.';

    public const INVALID_JSON_PAYLOAD_NOTIFICATION_ERROR = 'AmazonCallback: Invalid Notification JSON Payload.';

    public const UNKNOWN_TYPE_WARNING = 'Type not found in implementaion, no processing was done for type: %s.';
}
