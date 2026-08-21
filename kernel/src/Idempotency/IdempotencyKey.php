<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Idempotency;

use PeanutAdmin\Kernel\Api\ApiException;

final readonly class IdempotencyKey
{
    private function __construct(public string $hash) {}

    public static function fromString(?string $value): self
    {
        if (!is_string($value)
            || strlen($value) < 16
            || strlen($value) > 128
            || preg_match('/^[\x21-\x7E]+$/D', $value) !== 1) {
            throw new ApiException('IDEMPOTENCY_KEY_INVALID', 422, 'A valid Idempotency-Key is required.');
        }

        return new self(hash('sha256', $value));
    }
}
