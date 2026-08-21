<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use JsonException;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoRepository;

final class PdoMenuCatalogRepository extends PdoRepository implements MenuCatalogRepository
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
        $this->execute(<<<'SQL'
INSERT INTO pa_menu_definition (
    `key`, module_key, scope, parent_key, type, name, route_name, route_path,
    component_key, icon, sort_order, required_permission_id, client_keys_json,
    status, manifest_digest, created_at, updated_at
) VALUES (
    :menu_key, :module_key, :scope, :parent_key, :type, :name, :route_name, :route_path,
    :component_key, :icon, :sort_order, :permission_id, :client_keys,
    'active', :manifest_digest, :created_at, :updated_at
)
ON DUPLICATE KEY UPDATE
    module_key = VALUES(module_key), scope = VALUES(scope), parent_key = VALUES(parent_key),
    type = VALUES(type), name = VALUES(name), route_name = VALUES(route_name),
    route_path = VALUES(route_path), component_key = VALUES(component_key), icon = VALUES(icon),
    sort_order = VALUES(sort_order), required_permission_id = VALUES(required_permission_id),
    client_keys_json = VALUES(client_keys_json), status = 'active',
    manifest_digest = VALUES(manifest_digest), updated_at = VALUES(updated_at)
SQL, [
            'menu_key' => $definition->key,
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
            'permission_id' => $permissionId,
            'client_keys' => $clientKeys,
            'manifest_digest' => $manifestDigest,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function retireMissing(array $activeKeys): void
    {
        if ($activeKeys === []) {
            throw new DomainException('The active menu catalog cannot be empty.');
        }
        $placeholders = [];
        $parameters = ['updated_at' => $this->now()];
        foreach ($activeKeys as $index => $key) {
            $name = 'menu_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $key;
        }
        $this->execute(
            'UPDATE pa_menu_definition SET status = \'retired\', updated_at = :updated_at'
            . ' WHERE status = \'active\' AND `key` NOT IN (' . implode(', ', $placeholders) . ')',
            $parameters,
        );
    }

    public function activeDefinitions(string $scope): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT m.`key`, m.module_key, m.scope, m.parent_key, m.type, m.name,
       m.route_name, m.route_path, m.component_key, m.icon, m.sort_order,
       p.`key` AS required_permission, m.client_keys_json
FROM pa_menu_definition m
LEFT JOIN pa_permission p ON p.id = m.required_permission_id
WHERE m.scope = :scope AND m.status = 'active'
ORDER BY m.sort_order, m.`key`
SQL);
        $statement->execute(['scope' => $scope]);

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
        }, $statement->fetchAll()));
    }

    public function activeDeploymentModules(): array
    {
        $statement = $this->pdo->query(
            "SELECT module_key FROM pa_module_installation WHERE status = 'active' ORDER BY module_key",
        );
        if ($statement === false) {
            throw new DomainException('Could not load active deployment modules.');
        }

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function activeTenantModules(int $tenantId): array
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT module_key FROM pa_tenant_module
WHERE tenant_id = :tenant_id AND status = 'enabled'
  AND (effective_at IS NULL OR effective_at <= :effective_now)
  AND (expires_at IS NULL OR expires_at > :expires_now)
ORDER BY module_key
SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'effective_now' => $now,
            'expires_now' => $now,
        ]);

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function permissionId(string $key): int
    {
        $row = $this->fetchOne(
            "SELECT id FROM pa_permission WHERE `key` = :permission_key AND status = 'active'",
            ['permission_key' => $key],
        );

        return $row === null
            ? throw new DomainException("Menu permission is unavailable: {$key}")
            : (int) $row['id'];
    }
}
