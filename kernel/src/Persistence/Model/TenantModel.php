<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

use PeanutAdmin\Kernel\Tenancy\TenantScope;
use think\db\BaseQuery;
use think\Model;

/** Base for Core records whose tenant boundary was established before querying. */
abstract class TenantModel extends Model
{
    /** @var bool */ protected $autoWriteTimestamp = false;

    public function scopeTenant(BaseQuery $query, TenantScope $scope): void
    {
        $query->where($query->getTable() . '.tenant_id', $scope->tenantId());
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    final protected static function tenantAttributes(TenantScope $scope, array $attributes): array
    {
        if (array_key_exists('tenant_id', $attributes)
            && (int) $attributes['tenant_id'] !== $scope->tenantId()) {
            throw new \DomainException('TENANT_WRITE_OWNERSHIP_MISMATCH');
        }

        return ['tenant_id' => $scope->tenantId(), ...$attributes];
    }
}
