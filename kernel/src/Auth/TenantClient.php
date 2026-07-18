<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use InvalidArgumentException;

final readonly class TenantClient
{
    public string $refreshCookieName;

    public function __construct(public string $key)
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Invalid Tenant Client key.');
        }

        $this->refreshCookieName = '__Host-pa_tenant_refresh_' . $key;
    }
}
