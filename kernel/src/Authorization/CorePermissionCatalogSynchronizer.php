<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use PeanutAdmin\Kernel\Authorization\Persistence\AuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\DataConditionDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;

final readonly class CorePermissionCatalogSynchronizer
{
    private const MANIFEST_VERSION = '0.1.0';

    public function __construct(private AuthorizationCatalogRepository $catalog) {}

    public function synchronize(): void
    {
        foreach (CorePermissionCatalog::TENANT as $key) {
            $this->synchronizePermission($key, 'core');
        }
        foreach (CorePermissionCatalog::PLATFORM as $key) {
            $this->synchronizePermission($key, 'platform');
        }
        foreach ([
            'core.tenant_all' => ['tenant', 'none'],
            'core.self' => ['self', 'none'],
            'core.own_department' => ['department', 'none'],
            'core.department_tree' => ['department', 'none'],
            'core.specified_departments' => ['selected', 'department'],
            'core.specified_objects' => ['selected', 'resource'],
        ] as $key => [$category, $targetMode]) {
            $this->catalog->syncDataCondition(new DataConditionDefinition(
                $key,
                'core',
                $category,
                $targetMode,
                null,
                self::MANIFEST_VERSION,
                hash('sha256', $key . ':' . $category . ':' . $targetMode),
            ));
        }
    }

    private function synchronizePermission(string $key, string $moduleKey): void
    {
        $this->catalog->syncPermission(new PermissionDefinition(
            $key,
            $moduleKey,
            'api',
            $key,
            match (true) {
                str_contains($key, 'provision-owner') => 'critical',
                in_array($key, ['core.member.effective-access.read', 'platform.upgrade.read', 'platform.ops.read', 'platform.ops.logs.read'], true) => 'sensitive',
                in_array($key, ['platform.ops.backup.manage', 'platform.ops.restore.manage', 'platform.ops.maintenance.manage'], true) => 'critical',
                default => 'normal',
            },
            self::MANIFEST_VERSION,
        ));
    }
}
