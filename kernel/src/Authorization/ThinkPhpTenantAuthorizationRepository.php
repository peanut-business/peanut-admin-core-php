<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use JsonException;
use PeanutAdmin\Kernel\Module\Model\TenantModule;
use PeanutAdmin\Kernel\Persistence\Model\MemberRole;
use PeanutAdmin\Kernel\Persistence\Model\RolePermission;
use PeanutAdmin\Kernel\Persistence\Model\TenantMember;
use think\facade\Db;

final class ThinkPhpTenantAuthorizationRepository implements TenantAuthorizationRepository
{
    public function member(int $tenantId, int $memberId): ?array
    {
        $row = Db::name('tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('id', $memberId)
            ->field('id,display_name,status,primary_department_id')
            ->find();

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'display_name' => is_string($row['display_name']) ? $row['display_name'] : null,
            'status' => (string) $row['status'],
            'primary_department_id' => $row['primary_department_id'] === null
                ? null
                : (int) $row['primary_department_id'],
        ];
    }

    public function activeRoles(int $tenantId, int $memberId): array
    {
        $rows = TenantMember::alias('member')
            ->join(
                'member_role member_role',
                'member_role.tenant_id = member.tenant_id AND member_role.tenant_member_id = member.id',
            )
            ->join(
                'role role',
                "role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id AND role.status = 'active'",
            )
            ->where('member.tenant_id', $tenantId)
            ->where('member.id', $memberId)
            ->where('member.status', 'active')
            ->field(['role.id', 'role.key', 'role.name', 'role.is_builtin'])
            ->order('role.key')
            ->order('role.id')
            ->select()
            ->toArray();

        return array_values(array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'is_builtin' => (int) $row['is_builtin'] === 1,
        ], $rows));
    }

    public function revision(int $tenantId, int $memberId): string
    {
        $tenant = Db::name('tenant')
            ->where('id', $tenantId)
            ->field('status,authorization_revision')
            ->find();
        if ($tenant === null) {
            return hash('sha256', "missing:{$tenantId}:{$memberId}");
        }
        $member = Db::name('tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('id', $memberId)
            ->field('status,authorization_revision')
            ->find();
        $roles = MemberRole::alias('member_role')
            ->join('role role', 'role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id')
            ->where('member_role.tenant_id', $tenantId)
            ->where('member_role.tenant_member_id', $memberId)
            ->field('role.id,role.status,role.authorization_revision')
            ->order('role.id')
            ->select()
            ->toArray();
        $modules = TenantModule::alias('tenant_module')
            ->leftJoin('module_installation installation', 'installation.module_key = tenant_module.module_key')
            ->where('tenant_module.tenant_id', $tenantId)
            ->field([
                'tenant_module.module_key', 'tenant_module.status', 'tenant_module.authorization_revision',
                'tenant_module.effective_at', 'tenant_module.expires_at',
                'installation.status' => 'installation_status', 'installation.revision' => 'installation_revision',
            ])
            ->order('tenant_module.module_key')
            ->select()
            ->toArray();

        try {
            return hash('sha256', json_encode([
                'tenant' => $tenant,
                'member' => $member,
                'roles' => $roles,
                'modules' => $modules,
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', "invalid:{$tenantId}:{$memberId}");
        }
    }

    public function permissions(int $tenantId, int $memberId): EffectivePermissionSet
    {
        $tenantActive = Db::name('tenant')->where('id', $tenantId)->where('status', 'active')->value('id');
        if ($tenantActive === null) {
            return new EffectivePermissionSet([]);
        }

        $roles = $this->activeRoles($tenantId, $memberId);
        $roleIds = array_column($roles, 'id');
        $isTenantOwner = false;
        foreach ($roles as $role) {
            $isTenantOwner = $isTenantOwner
                || ($role['key'] === 'core.tenant-owner' && $role['is_builtin'] === true);
        }
        $permissions = [];
        if ($roleIds !== []) {
            $availableModules = TenantModule::alias('tenant_module')
                ->join(
                    'module_installation installation',
                    "installation.module_key = tenant_module.module_key AND installation.status = 'active'",
                )
                ->where('tenant_module.tenant_id', $tenantId)
                ->where('tenant_module.status', 'enabled')
                ->where(function ($query): void {
                    $query->whereNull('tenant_module.effective_at')
                        ->whereOr('tenant_module.effective_at', '<=', Db::raw('UTC_TIMESTAMP(3)'));
                })
                ->where(function ($query): void {
                    $query->whereNull('tenant_module.expires_at')
                        ->whereOr('tenant_module.expires_at', '>', Db::raw('UTC_TIMESTAMP(3)'));
                })
                ->column('tenant_module.module_key');
            $permissions = RolePermission::alias('role_permission')
                ->join('permission permission', "permission.id = role_permission.permission_id AND permission.status = 'active'")
                ->where('role_permission.tenant_id', $tenantId)
                ->whereIn('role_permission.role_id', $roleIds)
                ->whereNotLike('permission.key', 'platform.%')
                ->whereIn('permission.module_key', array_values(array_unique(['core', ...$availableModules])))
                ->distinct(true)
                ->order('permission.key')
                ->column('permission.key');
        }

        if ($isTenantOwner) {
            $permissions = [...$permissions, ...CorePermissionCatalog::TENANT];
        }

        return new EffectivePermissionSet(array_map('strval', $permissions));
    }
}
