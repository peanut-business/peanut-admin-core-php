<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use PeanutAdmin\Kernel\Authorization\Persistence\AuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\DataConditionDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\ProtectedResourceDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\ResourceOperationDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\TargetTypeDefinition;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;

final readonly class ModuleAuthorizationCatalogSynchronizer
{
    public function __construct(private AuthorizationCatalogRepository $catalog) {}

    public function synchronize(CompiledModuleRegistry $registry): void
    {
        (new CorePermissionCatalogSynchronizer($this->catalog))->synchronize();

        foreach ($registry->modules as $module) {
            $this->synchronizePermissions($module);
            $this->synchronizeTargetTypes($module);
            $this->synchronizeDataConditions($module);
        }
        foreach ($registry->modules as $module) {
            $this->synchronizeProtectedResources($module);
        }
    }

    private function synchronizePermissions(ManifestDocument $module): void
    {
        $moduleKey = $this->moduleString($module, 'key');
        $version = $this->moduleString($module, 'version');
        foreach ($this->catalogEntries($module, 'permissions') as $permission) {
            $this->catalog->syncPermission(new PermissionDefinition(
                $this->string($permission, 'key', $moduleKey),
                $moduleKey,
                $this->string($permission, 'type', $moduleKey),
                $this->string($permission, 'name', $moduleKey),
                $this->string($permission, 'risk_level', $moduleKey),
                $version,
            ));
        }
    }

    private function synchronizeTargetTypes(ManifestDocument $module): void
    {
        $moduleKey = $this->moduleString($module, 'key');
        $version = $this->moduleString($module, 'version');
        foreach ($this->catalogEntries($module, 'target_types') as $targetType) {
            $key = $this->string($targetType, 'key', $moduleKey);
            $idFormat = match ($this->string($targetType, 'id_format', $moduleKey)) {
                'integer-string' => 'decimal',
                'string' => 'string',
                default => throw new ModuleException(
                    'MODULE_MANIFEST_INVALID',
                    "Unsupported target identifier format in {$moduleKey}:{$key}.",
                ),
            };
            $this->catalog->syncTargetType(new TargetTypeDefinition(
                $key,
                $moduleKey,
                $this->string($targetType, 'name', $moduleKey),
                $this->string($targetType, 'resolver', $moduleKey),
                $this->string($targetType, 'catalog_provider', $moduleKey),
                $idFormat,
                $version,
                $this->entryDigest($module, 'target', $key),
            ));
        }
    }

    private function synchronizeDataConditions(ManifestDocument $module): void
    {
        $moduleKey = $this->moduleString($module, 'key');
        $version = $this->moduleString($module, 'version');
        foreach ($this->catalogEntries($module, 'data_conditions') as $condition) {
            $key = $this->string($condition, 'key', $moduleKey);
            $schema = $condition['config_schema'] ?? null;
            if ($schema !== null && !is_array($schema)) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid condition schema in {$moduleKey}:{$key}.");
            }
            $this->catalog->syncDataCondition(new DataConditionDefinition(
                $key,
                $moduleKey,
                $this->string($condition, 'category', $moduleKey),
                $this->string($condition, 'target_mode', $moduleKey),
                $schema,
                $version,
                $this->entryDigest($module, 'condition', $key),
            ));
        }
    }

    private function synchronizeProtectedResources(ManifestDocument $module): void
    {
        $moduleKey = $this->moduleString($module, 'key');
        $version = $this->moduleString($module, 'version');
        foreach ($this->catalogEntries($module, 'protected_resources') as $resource) {
            $resourceKey = $this->string($resource, 'key', $moduleKey);
            $this->catalog->syncProtectedResource(new ProtectedResourceDefinition(
                $resourceKey,
                $moduleKey,
                $this->string($resource, 'name', $moduleKey),
                $this->string($resource, 'ownership', $moduleKey),
                $this->string($resource, 'provider', $moduleKey),
                $version,
                $this->entryDigest($module, 'resource', $resourceKey),
            ));
            foreach ($this->entries($resource, 'operations', $moduleKey) as $operation) {
                $operationKey = $this->string($operation, 'key', $moduleKey);
                $operationId = $this->catalog->syncResourceOperation(new ResourceOperationDefinition(
                    $resourceKey,
                    $operationKey,
                    $this->string($operation, 'access_mode', $moduleKey),
                    $this->string($operation, 'target_cardinality', $moduleKey),
                    $this->string($operation, 'permission_match', $moduleKey),
                    $this->string($operation, 'audit_level', $moduleKey),
                    $this->entryDigest($module, 'operation', $resourceKey . ':' . $operationKey),
                ));
                $this->catalog->resetOperationRelations($operationId);
                foreach ($this->stringList($operation, 'permissions', $moduleKey) as $index => $permissionKey) {
                    $this->catalog->bindOperationPermission(
                        $operationId,
                        $this->catalog->permissionId($permissionKey),
                        $index,
                    );
                }
                foreach ($this->entries($operation, 'target_types', $moduleKey) as $targetType) {
                    $selectionPermission = $targetType['policy_selection_permission'] ?? null;
                    if ($selectionPermission !== null && !is_string($selectionPermission)) {
                        throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid selection permission in {$moduleKey}.");
                    }
                    $this->catalog->bindOperationTargetType(
                        $operationId,
                        $this->catalog->targetTypeId($this->string($targetType, 'target_resource_key', $moduleKey)),
                        $this->string($targetType, 'target_role', $moduleKey),
                        $this->string($targetType, 'input_mode', $moduleKey),
                        $selectionPermission === null ? null : $this->catalog->permissionId($selectionPermission),
                    );
                }
                foreach ($this->entries($operation, 'conditions', $moduleKey) as $condition) {
                    $selector = $condition['selector_resource_key'] ?? null;
                    if ($selector !== null && !is_string($selector)) {
                        throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid condition selector in {$moduleKey}.");
                    }
                    $this->catalog->bindOperationCondition(
                        $operationId,
                        $this->catalog->dataConditionId($this->string($condition, 'key', $moduleKey)),
                        $selector,
                    );
                }
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function catalogEntries(ManifestDocument $module, string $key): array
    {
        $catalog = $module->data['catalog'] ?? null;
        if (!is_array($catalog)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', 'Compiled Module catalog is missing.');
        }

        return $this->entries($catalog, $key, $this->moduleString($module, 'key'));
    }

    /**
     * @param array<string, mixed> $owner
     * @return list<array<string, mixed>>
     */
    private function entries(array $owner, string $key, string $moduleKey): array
    {
        $entries = $owner[$key] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Catalog list {$key} is invalid in {$moduleKey}.");
        }
        $normalized = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "Catalog entry {$key} is invalid in {$moduleKey}.");
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $owner
     * @return list<string>
     */
    private function stringList(array $owner, string $key, string $moduleKey): array
    {
        $values = $owner[$key] ?? null;
        if (!is_array($values) || !array_is_list($values) || $values === []) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "String list {$key} is invalid in {$moduleKey}.");
        }
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "String list {$key} is invalid in {$moduleKey}.");
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $owner */
    private function string(array $owner, string $key, string $moduleKey): string
    {
        $value = $owner[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Catalog field {$key} is invalid in {$moduleKey}.");
        }

        return $value;
    }

    private function moduleString(ManifestDocument $module, string $key): string
    {
        return $this->string($module->data, $key, 'manifest');
    }

    private function entryDigest(ManifestDocument $module, string $kind, string $key): string
    {
        return hash('sha256', $module->digest . ':' . $kind . ':' . $key);
    }
}
