<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

use DomainException;
use think\facade\Db;

final class ThinkPhpAuthorizationRevisionRepository implements AuthorizationRevisionRepository
{
    public function bumpTenant(int $tenantId): int
    {
        return $this->bump('tenant', ['id' => $tenantId]);
    }

    public function bumpMember(int $tenantId, int $memberId): int
    {
        return $this->bump('tenant_member', ['tenant_id' => $tenantId, 'id' => $memberId]);
    }

    public function bumpRole(int $tenantId, int $roleId): int
    {
        return $this->bump('role', ['tenant_id' => $tenantId, 'id' => $roleId]);
    }

    public function bumpTenantModule(int $tenantId, string $moduleKey): int
    {
        return $this->bump('tenant_module', ['tenant_id' => $tenantId, 'module_key' => $moduleKey]);
    }

    /** @param array<string, int|string> $identity */
    private function bump(string $table, array $identity): int
    {
        $query = Db::name($table);
        foreach ($identity as $column => $value) {
            $query->where($column, $value);
        }
        if ((clone $query)->update([
            'authorization_revision' => Db::raw('authorization_revision + 1'),
            'updated_at' => gmdate('Y-m-d H:i:s.000'),
        ]) !== 1) {
            throw new DomainException('Authorization revision target was not found.');
        }
        $revision = $query->value('authorization_revision');

        return $revision === null
            ? throw new DomainException('Authorization revision could not be loaded.')
            : (int) $revision;
    }
}
