<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Api;

use RuntimeException;

final class ApiException extends RuntimeException
{
    /** @param list<array{pointer: string, code: string, message: string}> $errors */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
