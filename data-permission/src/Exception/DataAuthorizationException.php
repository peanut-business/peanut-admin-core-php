<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Exception;

use RuntimeException;

final class DataAuthorizationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
