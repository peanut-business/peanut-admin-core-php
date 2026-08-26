<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

final class CorePermissionCatalog
{
    /** @var list<string> */
    public const TENANT = [
        'core.member.read',
        'core.member.effective-access.read',
        'core.member.create',
        'core.member.update',
        'core.member.role.assign',
        'core.member.suspend',
        'core.member.activate',
        'core.member.leave',
        'core.department.read',
        'core.department.create',
        'core.department.update',
        'core.department.move',
        'core.department.archive',
        'core.role.read',
        'core.role.create',
        'core.role.update',
        'core.role.archive',
        'core.role.permission.assign',
        'core.role.data-policy.read',
        'core.role.data-policy.manage',
        'core.permission.read',
        'core.module.read',
        'core.module.configure',
        'core.audit.read',
    ];

    /** @var list<string> */
    public const PLATFORM = [
        'platform.module.read',
        'platform.module.create',
        'platform.module.install',
        'platform.module.uninstall',
        'platform.module.disable',
        'platform.module.sync',
        'platform.tenant.read',
        'platform.tenant.create',
        'platform.tenant.update',
        'platform.tenant.lifecycle',
        'platform.tenant.provision-owner',
        'platform.tenant.module.manage',
        'platform.operator.read',
        'platform.operator.create',
        'platform.operator.update',
        'platform.operator.lifecycle',
        'platform.operator.role.assign',
        'platform.role.read',
        'platform.role.create',
        'platform.role.update',
        'platform.role.archive',
        'platform.role.permission.assign',
        'platform.permission.read',
        'platform.audit.read',
        'platform.upgrade.read',
        'platform.ops.read',
        'platform.ops.backup.manage',
        'platform.ops.restore.manage',
        'platform.ops.maintenance.manage',
        'platform.ops.logs.read',
    ];

    private function __construct() {}
}
