<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use PDO;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoRepository;

final class PdoTenantAuthorizationRepository extends PdoRepository implements TenantAuthorizationRepository
{
    public function member(int $tenantId, int $memberId): ?array
    {
        $row = $this->fetchOne(<<<'SQL'
SELECT id, display_name, status, primary_department_id
FROM pa_tenant_member
WHERE tenant_id = :tenant_id AND id = :member_id
SQL, ['tenant_id' => $tenantId, 'member_id' => $memberId]);

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
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT role.id, role.`key`, role.name, role.is_builtin
FROM pa_tenant_member member
JOIN pa_member_role member_role
  ON member_role.tenant_id = member.tenant_id
 AND member_role.tenant_member_id = member.id
JOIN pa_role role
  ON role.tenant_id = member_role.tenant_id
 AND role.id = member_role.role_id
 AND role.status = 'active'
WHERE member.tenant_id = :tenant_id
  AND member.id = :member_id
  AND member.status = 'active'
ORDER BY role.`key`, role.id
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'member_id' => $memberId]);

        $roles = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $roles[] = [
                'id' => (int) $row['id'],
                'key' => (string) $row['key'],
                'name' => (string) $row['name'],
                'is_builtin' => (int) $row['is_builtin'] === 1,
            ];
        }

        return $roles;
    }

    public function revision(int $tenantId, int $memberId): string
    {
        $row = $this->fetchOne(<<<'SQL'
SELECT
    t.status AS tenant_status,
    t.authorization_revision AS tenant_revision,
    tm.status AS member_status,
    tm.authorization_revision AS member_revision,
    COALESCE(GROUP_CONCAT(DISTINCT CONCAT(r.id, ':', r.status, ':', r.authorization_revision)
        ORDER BY r.id SEPARATOR '|'), '') AS role_revisions,
    COALESCE((
        SELECT GROUP_CONCAT(CONCAT(
            tenant_module.module_key, ':', tenant_module.status, ':',
            tenant_module.authorization_revision, ':',
            COALESCE(installation.status, 'missing'), ':',
            COALESCE(installation.revision, 0), ':',
            CASE
                WHEN installation.status = 'active'
                    AND tenant_module.status = 'enabled'
                    AND (tenant_module.effective_at IS NULL OR tenant_module.effective_at <= CURRENT_TIMESTAMP(3))
                    AND (tenant_module.expires_at IS NULL OR tenant_module.expires_at > CURRENT_TIMESTAMP(3))
                THEN 'available' ELSE 'unavailable'
            END
        ) ORDER BY tenant_module.module_key SEPARATOR '|')
        FROM pa_tenant_module tenant_module
        LEFT JOIN pa_module_installation installation
          ON installation.module_key = tenant_module.module_key
        WHERE tenant_module.tenant_id = t.id
    ), '') AS module_revisions
FROM pa_tenant t
LEFT JOIN pa_tenant_member tm ON tm.tenant_id = t.id AND tm.id = :member_id
LEFT JOIN pa_member_role mr ON mr.tenant_id = t.id AND mr.tenant_member_id = tm.id
LEFT JOIN pa_role r ON r.tenant_id = t.id AND r.id = mr.role_id
WHERE t.id = :tenant_id
GROUP BY t.id, t.status, t.authorization_revision, tm.status, tm.authorization_revision
SQL, ['tenant_id' => $tenantId, 'member_id' => $memberId]);

        if ($row === null) {
            return hash('sha256', "missing:{$tenantId}:{$memberId}");
        }

        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function permissions(int $tenantId, int $memberId): EffectivePermissionSet
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT DISTINCT r.`key` AS role_key, r.is_builtin, p.`key` AS permission_key
FROM pa_tenant t
JOIN pa_tenant_member tm
  ON tm.tenant_id = t.id AND tm.id = :member_id AND tm.status = 'active'
JOIN pa_member_role mr
  ON mr.tenant_id = t.id AND mr.tenant_member_id = tm.id
JOIN pa_role r
  ON r.tenant_id = t.id AND r.id = mr.role_id AND r.status = 'active'
LEFT JOIN pa_role_permission rp
  ON rp.tenant_id = t.id AND rp.role_id = r.id
LEFT JOIN pa_permission p
  ON p.id = rp.permission_id
 AND p.status = 'active'
 AND p.`key` NOT LIKE 'platform.%'
 AND (
    p.module_key = 'core'
    OR EXISTS (
        SELECT 1
        FROM pa_tenant_module tenant_module
        JOIN pa_module_installation installation
          ON installation.module_key = tenant_module.module_key
         AND installation.status = 'active'
        WHERE tenant_module.tenant_id = t.id
          AND tenant_module.module_key = p.module_key
          AND tenant_module.status = 'enabled'
          AND (tenant_module.effective_at IS NULL OR tenant_module.effective_at <= CURRENT_TIMESTAMP(3))
          AND (tenant_module.expires_at IS NULL OR tenant_module.expires_at > CURRENT_TIMESTAMP(3))
    )
 )
WHERE t.id = :tenant_id AND t.status = 'active'
ORDER BY r.`key`, p.`key`
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'member_id' => $memberId]);

        $permissions = [];
        $isTenantOwner = false;
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $isTenantOwner = $isTenantOwner
                || ($row['role_key'] === 'core.tenant-owner' && (int) $row['is_builtin'] === 1);
            if (is_string($row['permission_key'])) {
                $permissions[] = $row['permission_key'];
            }
        }

        if ($isTenantOwner) {
            $permissions = [...$permissions, ...CorePermissionCatalog::TENANT];
        }

        return new EffectivePermissionSet($permissions);
    }
}
