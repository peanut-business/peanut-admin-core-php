<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use think\db\BaseQuery;
use think\Model;

/** Model base for Edition-shaped tables that omit tenant_id in Standalone. */
abstract class EditionTenantModel extends Model
{
    /** @var bool */ protected $autoWriteTimestamp = false;

    public function scopeTenant(
        BaseQuery $query,
        TenantScope $scope,
        TenantPersistenceMode $mode,
        ?int $instanceTenantId = null,
    ): void {
        self::assertContext($scope, $mode, $instanceTenantId);
        if ($mode->usesTenantColumn()) {
            $query->where($query->getTable() . '.tenant_id', $scope->tenantId());
        }
    }

    /** @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    final public static function tenantAttributes(
        TenantScope $scope,
        TenantPersistenceMode $mode,
        ?int $instanceTenantId,
        array $attributes,
    ): array {
        self::assertContext($scope, $mode, $instanceTenantId);
        if (!$mode->usesTenantColumn()) {
            unset($attributes['tenant_id']);

            return $attributes;
        }
        if (array_key_exists('tenant_id', $attributes)
            && (int) $attributes['tenant_id'] !== $scope->tenantId()) {
            throw new \DomainException('TENANT_WRITE_OWNERSHIP_MISMATCH');
        }

        return ['tenant_id' => $scope->tenantId(), ...$attributes];
    }

    private static function assertContext(
        TenantScope $scope,
        TenantPersistenceMode $mode,
        ?int $instanceTenantId,
    ): void {
        if ($mode === TenantPersistenceMode::TenantScoped && $instanceTenantId !== null) {
            throw new \RuntimeException('TENANT_PERSISTENCE_CONFIGURATION_INVALID');
        }
        if ($mode === TenantPersistenceMode::InstanceScoped
            && ($instanceTenantId === null || $instanceTenantId < 1 || $scope->tenantId() !== $instanceTenantId)) {
            throw new \RuntimeException('TENANT_PERSISTENCE_CONTEXT_INVALID');
        }
    }
}
