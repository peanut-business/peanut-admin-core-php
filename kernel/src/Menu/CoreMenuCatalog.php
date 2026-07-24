<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

final class CoreMenuCatalog
{
    /** @return list<MenuDefinition> */
    public static function definitions(): array
    {
        return [
            new MenuDefinition('core.organization', 'core', 'tenant', null, 'group', '组织权限', null, null, null, null, ['admin-web'], 10, 'Users'),
            new MenuDefinition('core.member.list', 'core', 'tenant', 'core.organization', 'page', '成员管理', 'tenant.members.list', '/app/members', 'core.member.list', 'core.member.read', ['admin-web'], 11, 'UserRound'),
            new MenuDefinition('core.department.list', 'core', 'tenant', 'core.organization', 'page', '部门管理', 'tenant.departments.list', '/app/departments', 'core.department.list', 'core.department.read', ['admin-web'], 12, 'Network'),
            new MenuDefinition('core.role.list', 'core', 'tenant', 'core.organization', 'page', '角色管理', 'tenant.roles.list', '/app/roles', 'core.role.list', 'core.role.read', ['admin-web'], 13, 'Shield'),
            new MenuDefinition('core.governance.workbench', 'core', 'tenant', 'core.organization', 'page', '权限治理', 'tenant.governance.workbench', '/app/governance', 'core.governance.workbench', 'core.role.read', ['admin-web'], 14, 'SlidersHorizontal'),
            new MenuDefinition('core.system', 'core', 'tenant', null, 'group', '系统管理', null, null, null, null, ['admin-web'], 20, 'Settings'),
            new MenuDefinition('core.module.list', 'core', 'tenant', 'core.system', 'page', '模块管理', 'tenant.modules.list', '/app/modules', 'core.module.list', 'core.module.read', ['admin-web'], 21, 'Blocks'),
            new MenuDefinition('core.audit.list', 'core', 'tenant', 'core.system', 'page', '审计日志', 'tenant.audit.list', '/app/audit', 'core.audit.list', 'core.audit.read', ['admin-web'], 22, 'ScrollText'),
            new MenuDefinition('platform.governance', 'platform', 'platform', null, 'group', '平台治理', null, null, null, null, ['platform-web'], 10, 'Landmark'),
            new MenuDefinition('platform.tenant.list', 'platform', 'platform', 'platform.governance', 'page', '租户管理', 'platform.tenants.list', '/platform/tenants', 'platform.tenant.list', 'platform.tenant.read', ['platform-web'], 11, 'Building2'),
            new MenuDefinition('platform.operator.list', 'platform', 'platform', 'platform.governance', 'page', '平台操作员', 'platform.operators.list', '/platform/operators', 'platform.operator.list', 'platform.operator.read', ['platform-web'], 12, 'UserCog'),
            new MenuDefinition('platform.role.list', 'platform', 'platform', 'platform.governance', 'page', '平台角色', 'platform.roles.list', '/platform/roles', 'platform.role.list', 'platform.role.read', ['platform-web'], 13, 'ShieldCheck'),
            new MenuDefinition('platform.governance.workbench', 'platform', 'platform', 'platform.governance', 'page', '权限治理', 'platform.governance.workbench', '/platform/governance', 'platform.governance.workbench', 'platform.role.read', ['platform-web'], 14, 'SlidersHorizontal'),
            new MenuDefinition('platform.audit.list', 'platform', 'platform', 'platform.governance', 'page', '平台审计', 'platform.audit.list', '/platform/audit', 'platform.audit.list', 'platform.audit.read', ['platform-web'], 15, 'ScrollText'),
            new MenuDefinition('platform.upgrade.status', 'platform', 'platform', 'platform.governance', 'page', '版本与升级', 'platform.upgrade.status', '/platform/upgrade', 'platform.upgrade.status', 'platform.upgrade.read', ['platform-web'], 16, 'ArrowUpCircle'),
            new MenuDefinition('platform.ops.console', 'platform', 'platform', 'platform.governance', 'page', '运维控制台', 'platform.ops.console', '/platform/ops', 'platform.ops.console', 'platform.ops.read', ['platform-web'], 17, 'Activity'),
        ];
    }

    private function __construct() {}
}
