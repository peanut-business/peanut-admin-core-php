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

    protected function validateAndFilterData(bool $isUpdate): array
    {
        $result = parent::validateAndFilterData($isUpdate);
        if (!$isUpdate && $result[0] !== []) {
            // Explicit schema skips discovery; load it before INSERT so SHOW cannot reset the PDO insert ID.
            $this->getQuery()->getAutoInc();
        }
        return $result;
    }

    public function scopeTenant(BaseQuery $query, TenantScope $scope): void
    {
        $table = $query->getTable();
        $alias = $query->getOption('alias');
        $prefix = is_array($alias) && is_string($table) && isset($alias[$table]) && is_string($alias[$table]) && $alias[$table] !== ''
            ? $alias[$table]
            : (is_string($table) ? $table : '');
        $column = $prefix !== '' ? $prefix . '.tenant_id' : 'tenant_id';
        $query->where($column, $scope->tenantId());
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    final public static function tenantAttributes(TenantScope $scope, array $attributes): array
    {
        if (array_key_exists('tenant_id', $attributes)) {
            $provided = $attributes['tenant_id'];
            if ((!is_int($provided) && !(is_string($provided) && ctype_digit($provided)))
                || (int) $provided !== $scope->tenantId()) {
                throw new \DomainException('TENANT_WRITE_OWNERSHIP_MISMATCH');
            }
        }

        return [...$attributes, 'tenant_id' => $scope->tenantId()];
    }
}
