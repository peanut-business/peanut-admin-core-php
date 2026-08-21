<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

final class PlatformRefreshCookie
{
    public const NAME = '__Host-pa_platform_refresh';

    private function __construct() {}

    public static function issue(RawToken $token): string
    {
        return self::NAME . '=' . rawurlencode($token->expose())
            . '; Max-Age=1209600; Path=/; Secure; HttpOnly; SameSite=Lax';
    }

    public static function clear(): string
    {
        return self::NAME . '=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Lax';
    }
}
