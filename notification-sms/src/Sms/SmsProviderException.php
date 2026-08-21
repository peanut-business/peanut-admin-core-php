<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Sms;

use RuntimeException;

final class SmsProviderException extends RuntimeException
{
    private function __construct(public readonly string $safeCode, public readonly bool $retryable)
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $safeCode) !== 1) {
            throw new RuntimeException('Invalid SMS provider error code.');
        }
        parent::__construct($safeCode);
    }

    public static function retryable(string $safeCode = 'SMS_PROVIDER_UNAVAILABLE'): self
    {
        return new self($safeCode, true);
    }

    public static function permanent(string $safeCode = 'SMS_PROVIDER_REJECTED'): self
    {
        return new self($safeCode, false);
    }
}
