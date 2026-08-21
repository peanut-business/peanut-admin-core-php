<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use RuntimeException;

final class AuthorizationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode = 'AUTHZ_PERMISSION_DENIED')
    {
        parent::__construct($errorCode);
    }
}
