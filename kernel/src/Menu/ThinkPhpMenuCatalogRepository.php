<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use JsonException;
use PeanutAdmin\Kernel\Module\Model\ModuleInstallation;
use PeanutAdmin\Kernel\Module\Model\TenantModule;
use PeanutAdmin\Kernel\Persistence\Model\MenuDefinition as MenuDefinitionRecord;
use PeanutAdmin\Kernel\Persistence\Model\Permission;

final class ThinkPhpMenuCatalogRepository implements MenuCatalogRepository
{
    public function synchronize(MenuDefinition $definition, string $manifestDigest): void
    {
        $permissionId = $definition->requiredPermission === null
            ? null
            : $this->permissionId($definition->requiredPermission);
        try {
            $clientKeys = json_encode($definition->clientKeys, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new DomainException('Menu client keys are not valid JSON.', 0, $exception);
        }
        $now = $this->now();
        $data = [
            'module_key' => $definition->moduleKey,
            'scope' => $definition->scope,
            'parent_key' => $definition->parentKey,
            'type' => $definition->type,
            'name' => $definition->name,
            'route_name' => $definition->routeName,
            'route_path' => $definition->routePath,
            'component_key' => $definition->componentKey,
            'icon' => $definition->icon,
            'sort_order' => $definition->sortOrder,
            'required_permission_id' => $permissionId,
            'client_keys_json' => $clientKeys,
            'status' => 'active',
            'manifest_digest' => $manifestDigest,
            'updated_at' => $now,
        ];
        $existing = MenuDefinitionRecord::where('key', $definition->key)->value('id');
        if ($existing === null) {
            MenuDefinitionRecord::create(['key' => $definition->key, ...$data, 'created_at' => $now]);
            return;
        }
        MenuDefinitionRecord::where('id', (int) $existing)->update($data);
    }

    public function retireMissing(array $activeKeys): void
    {
        if ($activeKeys === []) {
            throw new DomainException('The active menu catalog cannot be empty.');
        }
        MenuDefinitionRecord::where('status', 'active')
            ->whereNotIn('key', $activeKeys)
            ->update(['status' => 'retired', 'updated_at' => $this->now()]);
    }

    public function activeDefinitions(string $scope): array
    {
        $rows = MenuDefinitionRecord::alias('menu')
            ->leftJoin('permission permission', 'permission.id = menu.required_permission_id')
            ->where('menu.scope', $scope)
            ->where('menu.status', 'active')
            ->field([
                'menu.key', 'menu.module_key', 'menu.scope', 'menu.parent_key', 'menu.type', 'menu.name',
                'menu.route_name', 'menu.route_path', 'menu.component_key', 'menu.icon', 'menu.sort_order',
                'permission.key' => 'required_permission', 'menu.client_keys_json',
            ])
            ->order('menu.sort_order')
            ->order('menu.key')
            ->select()
            ->toArray();

        return array_values(array_map(function (array $row): MenuDefinition {
            try {
                $clientKeys = json_decode((string) $row['client_keys_json'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new DomainException('Stored menu client keys are invalid.', 0, $exception);
            }
            if (!is_array($clientKeys) || !array_is_list($clientKeys)) {
                throw new DomainException('Stored menu client keys are invalid.');
            }

            return new MenuDefinition(
                (string) $row['key'],
                (string) $row['module_key'],
                (string) $row['scope'],
                $row['parent_key'] === null ? null : (string) $row['parent_key'],
                (string) $row['type'],
                (string) $row['name'],
                $row['route_name'] === null ? null : (string) $row['route_name'],
                $row['route_path'] === null ? null : (string) $row['route_path'],
                $row['component_key'] === null ? null : (string) $row['component_key'],
                $row['required_permission'] === null ? null : (string) $row['required_permission'],
                array_map('strval', $clientKeys),
                (int) $row['sort_order'],
                $row['icon'] === null ? null : (string) $row['icon'],
            );
        }, $rows));
    }

    public function activeDeploymentModules(): array
    {
        return array_values(array_map('strval', ModuleInstallation::where('status', 'active')
            ->order('module_key')
            ->column('module_key')));
    }

    public function activeTenantModules(int $tenantId): array
    {
        $now = $this->now();

        return array_values(array_map('strval', TenantModule::where('tenant_id', $tenantId)
            ->where('status', 'enabled')
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_at')->whereOr('effective_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')->whereOr('expires_at', '>', $now);
            })
            ->order('module_key')
            ->column('module_key')));
    }

    private function permissionId(string $key): int
    {
        $id = Permission::where('key', $key)->where('status', 'active')->value('id');

        return $id === null
            ? throw new DomainException("Menu permission is unavailable: {$key}")
            : (int) $id;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
