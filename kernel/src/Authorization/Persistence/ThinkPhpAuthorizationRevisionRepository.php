<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

use DomainException;
use PeanutAdmin\Kernel\Module\Model\TenantModule;
use PeanutAdmin\Kernel\Persistence\Model\Role;
use PeanutAdmin\Kernel\Persistence\Model\Tenant;
use PeanutAdmin\Kernel\Persistence\Model\TenantMember;
use think\db\BaseQuery;
use think\db\Raw;

final class ThinkPhpAuthorizationRevisionRepository implements AuthorizationRevisionRepository
{
    public function bumpTenant(int $tenantId): int
    {
        return $this->bump(Tenant::where('id', $tenantId));
    }

    public function bumpMember(int $tenantId, int $memberId): int
    {
        return $this->bump(TenantMember::where('tenant_id', $tenantId)->where('id', $memberId));
    }

    public function bumpRole(int $tenantId, int $roleId): int
    {
        return $this->bump(Role::where('tenant_id', $tenantId)->where('id', $roleId));
    }

    public function bumpTenantModule(int $tenantId, string $moduleKey): int
    {
        return $this->bump(TenantModule::where('tenant_id', $tenantId)->where('module_key', $moduleKey));
    }

    private function bump(BaseQuery $query): int
    {
        if ((clone $query)->update([
            'authorization_revision' => new Raw('authorization_revision + 1'),
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
