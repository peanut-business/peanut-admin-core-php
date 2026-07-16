<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Application;

use RuntimeException;

final class AdminAccessException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('RESOURCE_NOT_FOUND', 404, 'The requested resource was not found.');
    }

    public static function preconditionRequired(): self
    {
        return new self('PRECONDITION_REQUIRED', 428, 'If-Match is required.');
    }

    public static function revisionMismatch(): self
    {
        return new self('REVISION_MISMATCH', 412, 'The resource revision has changed.');
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($code, 409, $message);
    }

    public static function invalid(string $code, string $message): self
    {
        return new self($code, 422, $message);
    }
}
