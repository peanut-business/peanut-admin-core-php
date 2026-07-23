<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Sms;

use PeanutAdmin\NotificationSms\Application\NotificationException;

final readonly class SmsReceipt
{
    public function __construct(
        public string $providerKey,
        public string $providerMessageKey,
        public string $receiptCode,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $providerKey) !== 1
            || preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $providerMessageKey) !== 1
            || preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $receiptCode) !== 1
        ) {
            throw NotificationException::invalid('SMS_PROVIDER_RECEIPT_INVALID');
        }
    }
}
