<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

use PeanutAdmin\Kernel\Auth\AuthException;

final readonly class SystemContextFactory
{
    public function __construct(
        private SystemActorRegistry $actors,
        private SystemTenantResolver $tenants,
    ) {}

    public function tenant(
        string $actorKey,
        string $operation,
        string $tenantCode,
        string $operationId,
    ): TenantSystemContext {
        $definition = $this->actors->definition($actorKey);
        if (
            $definition === null
            || $definition->audience !== 'tenant'
            || !$definition->allows($operation)
            || $tenantCode === ''
        ) {
            throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
        }
        $tenantId = $this->tenants->activeTenantIdByCode($tenantCode);
        if ($tenantId === null) {
            throw new AuthException('AUTH_TENANT_UNAVAILABLE', 403);
        }

        return new TenantSystemContext($tenantId, $actorKey, $operation, $operationId);
    }

    public function platform(
        string $actorKey,
        string $operation,
        string $operationId,
    ): PlatformSystemContext {
        $definition = $this->actors->definition($actorKey);
        if (
            $definition === null
            || $definition->audience !== 'platform'
            || !$definition->allows($operation)
        ) {
            throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
        }

        return new PlatformSystemContext($actorKey, $operation, $operationId);
    }
}
