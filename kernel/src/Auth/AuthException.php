<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use RuntimeException;

final class AuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
    ) {
        parent::__construct(self::publicMessage($errorCode));
    }

    private static function publicMessage(string $errorCode): string
    {
        return match ($errorCode) {
            'AUTH_INVALID_CREDENTIALS' => 'Email or password is incorrect.',
            'AUTH_RATE_LIMITED' => 'Too many authentication attempts.',
            'AUTH_NO_AVAILABLE_TENANT' => 'No tenant is currently available.',
            'AUTH_CHALLENGE_INVALID',
            'AUTH_CHALLENGE_EXPIRED',
            'AUTH_CHALLENGE_USED' => 'Tenant selection credential is invalid.',
            'AUTH_REFRESH_REUSED' => 'Refresh credential reuse was detected.',
            'AUTH_AUDIENCE_MISMATCH' => 'Credential cannot be used for this entry.',
            'AUTH_TENANT_UNAVAILABLE' => 'Tenant is currently unavailable.',
            'AUTH_MEMBER_UNAVAILABLE' => 'Member is currently unavailable.',
            default => 'Authentication credential is invalid.',
        };
    }
}
