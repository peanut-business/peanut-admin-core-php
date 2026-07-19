<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Application;

use RuntimeException;

final class ReferenceCodeException extends RuntimeException
{
    private function __construct(
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

    public static function setNotFound(): self
    {
        return new self('REFERENCE_CODE_SET_NOT_FOUND', 404, 'The requested reference-code set is unavailable.');
    }

    public static function codeNotFound(): self
    {
        return new self('REFERENCE_CODE_NOT_FOUND', 404, 'The requested reference code is unavailable.');
    }

    public static function preconditionRequired(): self
    {
        return new self('PRECONDITION_REQUIRED', 428, 'A required strong reference-code precondition is missing or invalid.');
    }

    public static function alreadyExists(): self
    {
        return new self('REFERENCE_CODE_ALREADY_EXISTS', 412, 'The reference-code identity already exists.');
    }

    public static function revisionMismatch(): self
    {
        return new self('REFERENCE_CODE_REVISION_MISMATCH', 412, 'The reference-code revision has changed.');
    }

    public static function retired(): self
    {
        return new self('REFERENCE_CODE_RETIRED', 409, 'The reference-code identity is permanently retired.');
    }

    public static function internal(): self
    {
        return new self('INTERNAL_ERROR', 500, 'The reference-code operation could not be completed.');
    }
}
