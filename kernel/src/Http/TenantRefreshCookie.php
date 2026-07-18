<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Http;

use PeanutAdmin\Kernel\Auth\RawToken;
use PeanutAdmin\Kernel\Auth\TenantClient;

final class TenantRefreshCookie
{
    private function __construct() {}

    public static function name(TenantClient $client): string
    {
        return $client->refreshCookieName;
    }

    public static function issue(TenantClient $client, RawToken $token): string
    {
        return self::name($client) . '=' . rawurlencode($token->expose())
            . '; Max-Age=1209600; Path=/; Secure; HttpOnly; SameSite=Lax';
    }

    public static function clear(TenantClient $client): string
    {
        return self::name($client) . '=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Lax';
    }
}
