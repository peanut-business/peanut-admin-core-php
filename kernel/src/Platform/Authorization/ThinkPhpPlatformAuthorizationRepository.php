<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Authorization;

use JsonException;
use PeanutAdmin\Kernel\Authorization\CorePermissionCatalog;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Persistence\Model\PlatformOperator;
use PeanutAdmin\Kernel\Persistence\Model\PlatformOperatorRole;
use think\facade\Db;

final class ThinkPhpPlatformAuthorizationRepository implements PlatformAuthorizationRepository
{
    public function revision(int $operatorId): string
    {
        $operator = Db::name('platform_operator')
            ->where('id', $operatorId)
            ->field('status,security_revision')
            ->find();
        if ($operator === null) {
            return hash('sha256', "missing:{$operatorId}");
        }
        $roles = PlatformOperatorRole::alias('operator_role')
            ->join('platform_role role', 'role.id = operator_role.platform_role_id')
            ->where('operator_role.platform_operator_id', $operatorId)
            ->field('role.id,role.status,role.revision')
            ->order('role.id')
            ->select()
            ->toArray();

        try {
            return hash('sha256', json_encode(['operator' => $operator, 'roles' => $roles], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', "invalid:{$operatorId}");
        }
    }

    public function permissions(int $operatorId): EffectivePermissionSet
    {
        $rows = PlatformOperator::alias('operator')
            ->join('platform_operator_role operator_role', 'operator_role.platform_operator_id = operator.id')
            ->join(
                'platform_role role',
                "role.id = operator_role.platform_role_id AND role.status = 'active'",
            )
            ->leftJoin('platform_role_permission role_permission', 'role_permission.platform_role_id = role.id')
            ->leftJoin(
                'permission permission',
                "permission.id = role_permission.permission_id AND permission.status = 'active' AND permission.key LIKE 'platform.%'",
            )
            ->where('operator.id', $operatorId)
            ->where('operator.status', 'active')
            ->field(['role.key' => 'role_key', 'role.is_builtin', 'permission.key' => 'permission_key'])
            ->distinct(true)
            ->order('role.key')
            ->order('permission.key')
            ->select()
            ->toArray();

        $permissions = [];
        $isBootstrapOwner = false;
        foreach ($rows as $row) {
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
