<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleAvailabilityService;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

final readonly class ModuleAvailabilityAdapter
{
    public function __construct(
        private CompiledModuleRegistry $registry,
        private ModuleAvailabilityService $modules,
    ) {}

    public function assertAvailable(
        ExternalOperationDefinition $operation,
        TenantContext|PlatformContext $context,
        DateTimeImmutable $now,
    ): void {
        if (!in_array($operation->moduleKey, $this->registry->moduleKeys(), true)) {
            throw new ModuleException('MODULE_NOT_INSTALLED', 'The Module is not registered by this host.');
        }
        $this->modules->assertDeployment($operation->moduleKey);
        if ($context instanceof TenantContext) {
            $this->modules->assertTenant(
                TenantScope::fromTrustedContext($context->tenantId, 'external-operation-host'),
                $operation->moduleKey,
                $now,
            );
        }
    }
}
