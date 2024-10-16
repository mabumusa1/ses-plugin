<?php

namespace MauticPlugin\ScMailerSesBundle;

final class CallbackMessages
{
    public const INVALID_JSON_PAYLOAD_ERROR = 'MauticAmazonCallback: Invalid JSON Payload.';

    public const TYPE_MISSING_ERROR = 'MauticAmazonCallback: Key Type not found in payload.';

    public const SUBSCRIBE_ERROR = 'MauticAmazonCallback: SubscribeURL from Amazon SNS failed.';

    public const INVALID_JSON_PAYLOAD_NOTIFICATION_ERROR = 'MauticAmazonCallback: Invalid Notification JSON Payload.';

    public const UNKNOWN_TYPE_WARNING = 'MauticAmazonCallback: Type not found in implementaion, no processing was done for type: %s.';
    public const PROCESSED = 'MauticAmazonCallback: PROCESSED.';
}
