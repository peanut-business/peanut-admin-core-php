<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Authorization;

use PDO;
use PeanutAdmin\Kernel\Authorization\CorePermissionCatalog;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoRepository;

final class PdoPlatformAuthorizationRepository extends PdoRepository implements PlatformAuthorizationRepository
{
    public function revision(int $operatorId): string
    {
        $row = $this->fetchOne(<<<'SQL'
SELECT
    po.status AS operator_status,
    po.security_revision,
    COALESCE(GROUP_CONCAT(DISTINCT CONCAT(pr.id, ':', pr.status, ':', pr.revision)
        ORDER BY pr.id SEPARATOR '|'), '') AS role_revisions
FROM pa_platform_operator po
LEFT JOIN pa_platform_operator_role por ON por.platform_operator_id = po.id
LEFT JOIN pa_platform_role pr ON pr.id = por.platform_role_id
WHERE po.id = :operator_id
GROUP BY po.id, po.status, po.security_revision
SQL, ['operator_id' => $operatorId]);

        if ($row === null) {
            return hash('sha256', "missing:{$operatorId}");
        }

        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function permissions(int $operatorId): EffectivePermissionSet
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT DISTINCT pr.`key` AS role_key, pr.is_builtin, p.`key` AS permission_key
FROM pa_platform_operator po
JOIN pa_platform_operator_role por ON por.platform_operator_id = po.id
JOIN pa_platform_role pr ON pr.id = por.platform_role_id AND pr.status = 'active'
LEFT JOIN pa_platform_role_permission prp ON prp.platform_role_id = pr.id
LEFT JOIN pa_permission p
  ON p.id = prp.permission_id
 AND p.status = 'active'
 AND p.`key` LIKE 'platform.%'
WHERE po.id = :operator_id AND po.status = 'active'
ORDER BY pr.`key`, p.`key`
SQL);
        $statement->execute(['operator_id' => $operatorId]);

        $permissions = [];
        $isBootstrapOwner = false;
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $isBootstrapOwner = $isBootstrapOwner
                || ($row['role_key'] === 'platform.bootstrap-owner' && (int) $row['is_builtin'] === 1);
            if (is_string($row['permission_key'])) {
                $permissions[] = $row['permission_key'];
            }
        }

        if ($isBootstrapOwner) {
            $permissions = [...$permissions, ...CorePermissionCatalog::PLATFORM];
        }

        return new EffectivePermissionSet($permissions);
    }
}
