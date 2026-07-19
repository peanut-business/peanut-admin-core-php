<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleGuard;

final readonly class ModuleAvailabilityAdapter
{
    public function __construct(
        private CompiledModuleRegistry $registry,
        private ModuleGuard $guard,
    ) {}

    public function assertAvailable(
        ExternalOperationDefinition $operation,
        TenantContext|PlatformContext $context,
        DateTimeImmutable $now,
    ): void {
        if (!in_array($operation->moduleKey, $this->registry->moduleKeys(), true)) {
            throw new ModuleException('MODULE_NOT_INSTALLED', 'The Module is not registered by this host.');
        }
        $this->guard->assertDeployment($operation->moduleKey);
        if ($context instanceof TenantContext) {
            $this->guard->assertTenant($context->tenantId, $operation->moduleKey, $now);
        }
    }
}
