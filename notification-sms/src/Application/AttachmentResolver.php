<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Application;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface AttachmentResolver
{
    /**
     * The implementation must return only a ready File/Media object owned by
     * the context Tenant. Archived and cross-Tenant objects fail closed.
     */
    public function snapshot(TenantContext $context, string $fileKey): AttachmentReference;
}
