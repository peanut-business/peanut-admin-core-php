<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Sms;

final class DisabledSmsProvider implements SmsProvider
{
    public function key(): string
    {
        return 'disabled';
    }

    public function send(SmsSendRequest $request): SmsReceipt
    {
        throw SmsProviderException::permanent('SMS_PROVIDER_UNAVAILABLE');
    }
}
