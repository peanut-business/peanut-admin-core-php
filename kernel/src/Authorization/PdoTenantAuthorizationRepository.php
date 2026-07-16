<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use PDO;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoRepository;

final class PdoTenantAuthorizationRepository extends PdoRepository implements TenantAuthorizationRepository
{
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
            module_key, ':', status, ':', authorization_revision, ':',
            CASE
                WHEN status = 'enabled'
                    AND (effective_at IS NULL OR effective_at <= CURRENT_TIMESTAMP(3))
                    AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP(3))
                THEN 'available' ELSE 'unavailable'
            END
        ) ORDER BY module_key SEPARATOR '|')
        FROM pa_tenant_module
        WHERE tenant_id = t.id
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
