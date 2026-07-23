<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Application;

use RuntimeException;

final class SettingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $code, string $message): self
    {
        return new self($code, 422, $message);
    }

    public static function unavailable(string $code, string $message): self
    {
        return new self($code, 503, $message);
    }

    public static function notFound(string $code = 'SETTING_NOT_FOUND'): self
    {
        return new self($code, 404, 'The requested setting is unavailable.');
    }

    public static function preconditionRequired(): self
    {
        return new self('PRECONDITION_REQUIRED', 428, 'A strong setting precondition is required.');
    }

    public static function revisionMismatch(): self
    {
        return new self('SETTING_REVISION_MISMATCH', 412, 'The setting revision has changed.');
    }

    public static function conflict(): self
    {
        return new self('SETTING_VALUE_CONFLICT', 409, 'The setting value conflicts with an existing value.');
    }
}
