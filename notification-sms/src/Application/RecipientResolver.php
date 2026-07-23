<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Application;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface RecipientResolver
{
    /**
     * Resolves an active member in the context Tenant. When SMS is requested,
     * the snapshot contains only a masked number and a keyed/tenant-scoped
     * digest; the raw number must not be persisted in this package.
     */
    public function snapshot(TenantContext $context, int $memberId, bool $requiresSms): RecipientSnapshot;
}
