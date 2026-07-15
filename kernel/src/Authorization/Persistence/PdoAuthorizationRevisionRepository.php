<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

use DomainException;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoRepository;

final class PdoAuthorizationRevisionRepository extends PdoRepository implements AuthorizationRevisionRepository
{
    public function bumpTenant(int $tenantId): int
    {
        return $this->bump(
            'UPDATE pa_tenant SET authorization_revision = authorization_revision + 1, updated_at = :updated_at WHERE id = :tenant_id',
            ['updated_at' => $this->now(), 'tenant_id' => $tenantId],
            'SELECT authorization_revision FROM pa_tenant WHERE id = :tenant_id',
            ['tenant_id' => $tenantId],
        );
    }

    public function bumpMember(int $tenantId, int $memberId): int
    {
        return $this->bump(
            'UPDATE pa_tenant_member SET authorization_revision = authorization_revision + 1, updated_at = :updated_at WHERE tenant_id = :tenant_id AND id = :member_id',
            ['updated_at' => $this->now(), 'tenant_id' => $tenantId, 'member_id' => $memberId],
            'SELECT authorization_revision FROM pa_tenant_member WHERE tenant_id = :tenant_id AND id = :member_id',
            ['tenant_id' => $tenantId, 'member_id' => $memberId],
        );
    }

    public function bumpRole(int $tenantId, int $roleId): int
    {
        return $this->bump(
            'UPDATE pa_role SET authorization_revision = authorization_revision + 1, updated_at = :updated_at WHERE tenant_id = :tenant_id AND id = :role_id',
            ['updated_at' => $this->now(), 'tenant_id' => $tenantId, 'role_id' => $roleId],
            'SELECT authorization_revision FROM pa_role WHERE tenant_id = :tenant_id AND id = :role_id',
            ['tenant_id' => $tenantId, 'role_id' => $roleId],
        );
    }

    public function bumpTenantModule(int $tenantId, string $moduleKey): int
    {
        return $this->bump(
            'UPDATE pa_tenant_module SET authorization_revision = authorization_revision + 1, updated_at = :updated_at WHERE tenant_id = :tenant_id AND module_key = :module_key',
            ['updated_at' => $this->now(), 'tenant_id' => $tenantId, 'module_key' => $moduleKey],
            'SELECT authorization_revision FROM pa_tenant_module WHERE tenant_id = :tenant_id AND module_key = :module_key',
            ['tenant_id' => $tenantId, 'module_key' => $moduleKey],
        );
    }

    /**
     * @param array<string, int|string|null> $updateParameters
     * @param array<string, int|string|null> $selectParameters
     */
    private function bump(
        string $updateSql,
        array $updateParameters,
        string $selectSql,
        array $selectParameters,
    ): int {
        if ($this->execute($updateSql, $updateParameters) !== 1) {
            throw new DomainException('Authorization revision target was not found.');
        }
        $row = $this->fetchOne($selectSql, $selectParameters);

        return $row === null
            ? throw new DomainException('Authorization revision could not be loaded.')
            : (int) $row['authorization_revision'];
    }
}
