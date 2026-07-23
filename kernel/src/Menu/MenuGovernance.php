<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

use PeanutAdmin\Kernel\Authorization\Governance\GovernanceException;
use PeanutAdmin\Kernel\Authorization\Governance\GovernancePermissionCatalog;
use PeanutAdmin\Kernel\Module\ModuleException;

final class MenuGovernance
{
    /** @var array<string, MenuDefinition> */
    private array $menus = [];

    /** @var array<string, GovernanceRoute> */
    private array $routes = [];

    /**
     * @param list<MenuDefinition> $menus
     * @param list<GovernanceRoute> $routes
     */
    public function __construct(
        array $menus,
        array $routes,
        private readonly GovernancePermissionCatalog $permissions,
        private readonly MenuIconRegistry $icons,
    ) {
        try {
            new MenuRegistry($menus);
        } catch (ModuleException $exception) {
            throw new GovernanceException('GOVERNANCE_MENU_INVALID', 'The menu catalog is structurally invalid.');
        }
        foreach ($routes as $route) {
            if (isset($this->routes[$route->name])) {
                throw new GovernanceException('GOVERNANCE_ROUTE_CONFLICT', 'A governance route is declared more than once.');
            }
            $this->routes[$route->name] = $route;
        }
        foreach ($menus as $menu) {
            $this->menus[$menu->key] = $menu;
            $this->icons->require($menu->icon);
            if ($menu->type !== 'page') {
                continue;
            }
            $route = $this->routes[$menu->routeName ?? ''] ?? throw new GovernanceException(
                'GOVERNANCE_ROUTE_UNDECLARED',
                'A page menu references an undeclared route.',
            );
            if ($route->audience !== $menu->scope
                || ($menu->moduleKey === 'core' ? $route->moduleKey !== null : $route->moduleKey !== $menu->moduleKey)) {
                throw new GovernanceException('GOVERNANCE_ROUTE_AUDIENCE_MISMATCH', 'A page menu route belongs to another audience or Module.');
            }
            $permission = $menu->requiredPermission ?? '';
            $this->permissions->require($permission, $menu->scope);
            if (!in_array($permission, $route->permissionKeys, true)) {
                throw new GovernanceException('GOVERNANCE_ROUTE_PERMISSION_MISMATCH', 'A page menu and its route declare different access.');
            }
        }
    }

    /**
     * @param list<string> $deploymentModules
     * @param list<string> $tenantModules
     * @param list<string> $grantedPermissions
     * @return array<string, MenuVisibilityExplanation>
     */
    public function explain(
        string $audience,
        string $clientKey,
        array $deploymentModules,
        array $tenantModules,
        array $grantedPermissions,
    ): array {
        if (!in_array($audience, ['tenant', 'platform'], true)) {
            throw new GovernanceException('GOVERNANCE_AUDIENCE_INVALID', 'The governance audience is invalid.');
        }
        $result = [];
        foreach ($this->menus as $key => $menu) {
            $reason = 'visible';
            if ($menu->scope !== $audience) {
                $reason = 'audience_mismatch';
            } elseif (!in_array($clientKey, $menu->clientKeys, true)) {
                $reason = 'client_unavailable';
            } elseif ($menu->moduleKey !== 'core' && !in_array($menu->moduleKey, $deploymentModules, true)) {
                $reason = 'deployment_module_unavailable';
            } elseif ($menu->moduleKey !== 'core' && $audience === 'tenant' && !in_array($menu->moduleKey, $tenantModules, true)) {
                $reason = 'tenant_module_disabled';
            } elseif ($menu->requiredPermission !== null && !in_array($menu->requiredPermission, $grantedPermissions, true)) {
                $reason = 'permission_not_granted';
            }
            $route = $menu->routeName === null ? null : $this->routes[$menu->routeName];
            $result[$key] = new MenuVisibilityExplanation(
                $key,
                $reason === 'visible',
                $reason,
                $route?->path,
                $this->icons->require($menu->icon),
            );
        }

        do {
            $changed = false;
            foreach ($this->menus as $key => $menu) {
                if (!$result[$key]->visible) {
                    continue;
                }
                if ($menu->parentKey !== null && !$result[$menu->parentKey]->visible) {
                    $result[$key] = new MenuVisibilityExplanation($key, false, 'parent_hidden', $result[$key]->trustedRoutePath, $result[$key]->icon);
                    $changed = true;
                    continue;
                }
                if ($menu->type === 'group' && !$this->hasVisibleChild($key, $result)) {
                    $result[$key] = new MenuVisibilityExplanation($key, false, 'empty_group', null, $result[$key]->icon);
                    $changed = true;
                }
            }
        } while ($changed);

        return $result;
    }

    /** @param array<string, MenuVisibilityExplanation> $result */
    private function hasVisibleChild(string $parentKey, array $result): bool
    {
        foreach ($this->menus as $key => $menu) {
            if ($menu->parentKey === $parentKey && $result[$key]->visible) {
                return true;
            }
        }

        return false;
    }
}
