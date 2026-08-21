<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Context;

use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class AuthorizationContext
{
    public function __construct(
        public TenantContext $tenant,
        public ?int $primaryDepartmentId,
    ) {}
}
