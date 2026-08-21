<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Http\PermissionMiddleware;

final readonly class PermissionAdapter
{
    public function __construct(private PermissionMiddleware $permissions) {}

    public function authorize(
        ExternalOperationDefinition $operation,
        TenantContext|PlatformContext $context,
    ): void {
        if ($context instanceof TenantContext) {
            $this->permissions->authorizeTenant($context, $operation->permission);
            return;
        }
        $this->permissions->authorizePlatform($context, $operation->permission);
    }
}
