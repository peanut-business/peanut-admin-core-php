<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Protection;

use RuntimeException;

/** Errors must never contain plaintext, ciphertext, keys, or field identities. */
final class FieldProtectionException extends RuntimeException
{
    private function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    public static function unavailable(): self
    {
        return new self('FIELD_PROTECTION_UNAVAILABLE');
    }

    public static function invalidValue(): self
    {
        return new self('FIELD_PROTECTION_INVALID_VALUE');
    }

    public static function denied(): self
    {
        return new self('FIELD_REVEAL_DENIED');
    }
}
