<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Sms;

use PeanutAdmin\NotificationSms\Application\NotificationException;

final readonly class SmsRecipient
{
    public string $masked;
    public string $digest;

    public function __construct(private string $e164, string $digestKey)
    {
        if (preg_match('/^\+[1-9][0-9]{7,14}$/D', $e164) !== 1 || strlen($digestKey) < 32) {
            throw NotificationException::recipientUnavailable();
        }
        $digits = substr($e164, 1);
        $prefixLength = min(3, max(1, strlen($digits) - 7));
        $this->masked = '+' . substr($digits, 0, $prefixLength)
            . str_repeat('*', strlen($digits) - $prefixLength - 3)
            . substr($digits, -3);
        $this->digest = hash_hmac('sha256', $e164, $digestKey);
    }

    public function number(): string
    {
        return $this->e164;
    }
}
