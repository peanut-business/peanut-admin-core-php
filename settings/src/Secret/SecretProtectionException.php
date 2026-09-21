<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Secret;

use RuntimeException;

/** Technical failure raised by the credential-protection boundary. */
final class SecretProtectionException extends RuntimeException
{
    private function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    public static function invalidValue(): self
    {
        return new self('SETTING_SECRET_VALUE_INVALID');
    }

    public static function unavailable(): self
    {
        return new self('SETTING_SECRET_UNAVAILABLE');
    }
}
