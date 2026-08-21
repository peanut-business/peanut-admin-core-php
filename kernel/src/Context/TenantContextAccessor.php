<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\TenantContext;

final class TenantContextAccessor
{
    private ?TenantContext $context = null;

    public function bind(TenantContext $context): void
    {
        if ($this->context !== null) {
            throw new AuthException('CONTEXT_TENANT_MISMATCH', 403);
        }
        $this->context = $context;
    }

    public function require(): TenantContext
    {
        return $this->context ?? throw new AuthException('CONTEXT_TENANT_REQUIRED', 403);
    }

    public function clear(): void
    {
        $this->context = null;
    }
}
