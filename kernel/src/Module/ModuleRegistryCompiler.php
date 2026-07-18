<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use PeanutAdmin\Kernel\Authorization\CorePermissionCatalog;

final readonly class ModuleRegistryCompiler
{
    private const CORE_CONDITIONS = [
        'core.tenant_all',
        'core.self',
        'core.own_department',
        'core.department_tree',
        'core.specified_departments',
        'core.specified_objects',
    ];

    /**
     * @param list<string> $frontendComponents
     * @param list<string> $reservedTables
     * @param non-empty-list<string> $registeredClientKeys
     */
    public function __construct(
        private ManifestSchemaValidator $schemaValidator,
        private VersionConstraintMatcher $versionMatcher,
        private ContractInspector $contractInspector,
        private string $kernelVersion,
        private array $frontendComponents,
        private ModuleHostLayout $layout,
        private array $reservedTables,
        private array $registeredClientKeys,
    ) {}

    /** @param list<ManifestDocument> $documents */
    public function compile(array $documents): CompiledModuleRegistry
    {
        $byKey = [];
        foreach ($documents as $document) {
            $this->schemaValidator->assertValid($document->object);
            $key = $this->string($document, 'key');
            $moduleKey = ModuleKey::fromString($key);
            if (isset($byKey[$key])) {
                throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Duplicate module key: {$key}");
            }
            if ((int) ($document->data['schema_version'] ?? 0) !== 1) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "Unsupported manifest schema for {$key}.");
            }
            if (!$this->versionMatcher->matches($this->kernelVersion, $this->string($document, 'kernel_constraint'))) {
                throw new ModuleException('MODULE_VERSION_INCOMPATIBLE', "Kernel version is incompatible with {$key}.");
            }
            $provider = $this->nestedString($document, 'backend', 'provider');
            if (!str_starts_with($provider, $this->layout->backendNamespace($moduleKey))
                || !$this->contractInspector->implements($provider, ModuleProvider::class)) {
                throw new ModuleException('MODULE_CONTRACT_MISSING', "Invalid ModuleProvider for {$key}.");
            }
            $byKey[$key] = $document;
        }

        $ordered = $this->topologicalOrder($byKey);
        $targetOwners = [];
        $tableOwners = [];
        $permissionOwners = array_fill_keys(
            [...CorePermissionCatalog::TENANT, ...CorePermissionCatalog::PLATFORM],
            'core',
        );
        $conditionOwners = array_fill_keys(self::CORE_CONDITIONS, 'core');
        $dependencyKeys = [];
        $menus = [];
        foreach ($ordered as $document) {
            $key = $this->string($document, 'key');
            $dependencyKeys[$key] = $this->dependencyKeys($document);
            $catalog = $this->catalog($document);
            foreach ($catalog['target_types'] ?? [] as $target) {
                $targetKey = $this->arrayString($target, 'key', $key);
                $this->claim($targetOwners, $targetKey, $key, 'target type');
                $this->assertOwnedContract($this->arrayString($target, 'resolver', $key), 'TargetResolver', $key);
                $this->assertOwnedContract(
                    $this->arrayString($target, 'catalog_provider', $key),
                    'TargetCatalogProvider',
                    $key,
                );
            }
            foreach ($catalog['permissions'] ?? [] as $permission) {
                $this->claim($permissionOwners, $this->arrayString($permission, 'key', $key), $key, 'permission');
            }
            foreach ($catalog['data_conditions'] ?? [] as $condition) {
                $this->claim($conditionOwners, $this->arrayString($condition, 'key', $key), $key, 'data condition');
            }
            $database = is_array($document->data['database'] ?? null) ? $document->data['database'] : [];
            foreach ($database['owned_tables'] ?? [] as $table) {
                if (!is_string($table) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $table) !== 1) {
                    throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid owned table in {$key}.");
                }
                if (in_array($table, $this->reservedTables, true)) {
                    throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Reserved table cannot be owned by {$key}: {$table}");
                }
                $this->claim($tableOwners, $table, $key, 'table');
            }
            $contracts = is_array($document->data['contracts'] ?? null) ? $document->data['contracts'] : [];
            foreach ($contracts['exports'] ?? [] as $contract) {
                if (!is_string($contract) || !$this->contractInspector->classExists($contract)) {
                    throw new ModuleException('MODULE_CONTRACT_MISSING', "Missing exported contract in {$key}.");
                }
            }
        }

        foreach ($ordered as $document) {
            $key = $this->string($document, 'key');
            $catalog = $this->catalog($document);
            foreach ($catalog['protected_resources'] ?? [] as $resource) {
                $this->validateProtectedResource(
                    $resource,
                    $key,
                    $dependencyKeys[$key],
                    $targetOwners,
                    $permissionOwners,
                    $conditionOwners,
                );
            }
            foreach ($catalog['menus'] ?? [] as $menu) {
                $menuKey = $this->arrayString($menu, 'key', $key);
                $this->claimMenu(
                    $menus,
                    $menuKey,
                    $key,
                    $menu,
                    $dependencyKeys[$key],
                    $permissionOwners,
                );
            }
        }

        return new CompiledModuleRegistry(
            $ordered,
            $targetOwners,
            $tableOwners,
            $menus,
            hash('sha256', implode('|', array_map(
                static fn(ManifestDocument $document): string => $document->digest,
                $ordered,
            ))),
        );
    }

    /**
     * @param array<string, ManifestDocument> $byKey
     * @return list<ManifestDocument>
     */
    private function topologicalOrder(array $byKey): array
    {
        $visiting = [];
        $visited = [];
        $ordered = [];
        $visit = function (string $key) use (&$visit, &$visiting, &$visited, &$ordered, $byKey): void {
            if (isset($visited[$key])) {
                return;
            }
            if (isset($visiting[$key])) {
                throw new ModuleException('MODULE_DEPENDENCY_CYCLE', "Module dependency cycle includes {$key}.");
            }
            $document = $byKey[$key] ?? throw new ModuleException('MODULE_DEPENDENCY_MISSING', "Missing module dependency: {$key}");
            $visiting[$key] = true;
            foreach ($this->dependencies($document) as $dependency) {
                $dependencyKey = $this->arrayString($dependency, 'module_key', $key);
                $dependencyDocument = $byKey[$dependencyKey] ?? throw new ModuleException(
                    'MODULE_DEPENDENCY_MISSING',
                    "{$key} requires missing module {$dependencyKey}.",
                );
                if (!$this->versionMatcher->matches(
                    $this->string($dependencyDocument, 'version'),
                    $this->arrayString($dependency, 'version', $key),
                )) {
                    throw new ModuleException('MODULE_VERSION_INCOMPATIBLE', "{$key} dependency version is incompatible.");
                }
                $visit($dependencyKey);
            }
            unset($visiting[$key]);
            $visited[$key] = true;
            $ordered[] = $document;
        };

        $keys = array_keys($byKey);
        sort($keys);
        foreach ($keys as $key) {
            $visit($key);
        }

        return $ordered;
    }

    /** @return list<array<string, mixed>> */
    private function dependencies(ManifestDocument $document): array
    {
        $dependencies = $document->data['dependencies'] ?? [];
        return is_array($dependencies) && array_is_list($dependencies) ? $dependencies : [];
    }

    /** @return array<string, true> */
    private function dependencyKeys(ManifestDocument $document): array
    {
        $keys = [];
        foreach ($this->dependencies($document) as $dependency) {
            $keys[$this->arrayString($dependency, 'module_key', $this->string($document, 'key'))] = true;
        }

        return $keys;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function catalog(ManifestDocument $document): array
    {
        $catalog = $document->data['catalog'] ?? [];
        return is_array($catalog) ? $catalog : [];
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, true> $dependencies
     * @param array<string, string> $targetOwners
     * @param array<string, string> $permissionOwners
     * @param array<string, string> $conditionOwners
     */
    private function validateProtectedResource(
        array $resource,
        string $moduleKey,
        array $dependencies,
        array $targetOwners,
        array $permissionOwners,
        array $conditionOwners,
    ): void {
        $ownership = $this->arrayString($resource, 'ownership', $moduleKey);
        $this->assertOwnedContract(
            $this->arrayString($resource, 'provider', $moduleKey),
            'ResourceQueryPolicyProvider',
            $moduleKey,
        );
        if ($ownership === 'shared_master') {
            $scopeProvider = $resource['scope_provider'] ?? null;
            if (!is_string($scopeProvider) || $scopeProvider === '') {
                throw new ModuleException('MODULE_CONTRACT_MISSING', "shared_master in {$moduleKey} requires a scope provider.");
            }
            $this->assertOwnedContract($scopeProvider, 'SharedMasterScopeProvider', $moduleKey);
        }
        $operations = $resource['operations'] ?? [];
        if (!is_array($operations) || !array_is_list($operations)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid operations in {$moduleKey}.");
        }
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid operation in {$moduleKey}.");
            }
            foreach ($operation['permissions'] ?? [] as $permissionKey) {
                if (!is_string($permissionKey) || $permissionKey === '') {
                    throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid operation permission in {$moduleKey}.");
                }
                $this->assertAccessibleOwner(
                    $permissionKey,
                    'permission',
                    $moduleKey,
                    $dependencies,
                    $permissionOwners,
                );
            }
            $cardinality = $operation['target_cardinality'] ?? null;
            if (!is_string($cardinality) || !in_array($cardinality, [
                'none', 'one_required', 'zero_or_one', 'many_readable', 'aggregate_read', 'policy_publish', 'bulk_write',
            ], true)) {
                throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Operation cardinality is missing or invalid in {$moduleKey}.");
            }
            $targetTypes = $operation['target_types'] ?? [];
            if (!is_array($targetTypes) || !array_is_list($targetTypes)) {
                throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Operation target types are missing in {$moduleKey}.");
            }
            $relations = [];
            foreach ($targetTypes as $targetType) {
                if (!is_array($targetType)) {
                    throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Operation target relation must be structured in {$moduleKey}.");
                }
                $targetResourceKey = $this->arrayString($targetType, 'target_resource_key', $moduleKey);
                $targetRole = $this->arrayString($targetType, 'target_role', $moduleKey);
                $inputMode = $this->arrayString($targetType, 'input_mode', $moduleKey);
                if (!isset($targetOwners[$targetResourceKey])) {
                    throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Operation references unknown target type in {$moduleKey}.");
                }
                $this->assertAccessibleOwner(
                    $targetResourceKey,
                    'target type',
                    $moduleKey,
                    $dependencies,
                    $targetOwners,
                );
                if (!in_array($inputMode, ['explicit', 'derived', 'either'], true)) {
                    throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Operation target input mode is invalid in {$moduleKey}.");
                }
                $selectionPermission = $targetType['policy_selection_permission'] ?? null;
                if ($selectionPermission !== null && (!is_string($selectionPermission) || $selectionPermission === '')) {
                    throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Policy selection permission is invalid in {$moduleKey}.");
                }
                if (is_string($selectionPermission)) {
                    $this->assertAccessibleOwner(
                        $selectionPermission,
                        'permission',
                        $moduleKey,
                        $dependencies,
                        $permissionOwners,
                    );
                }
                $relationKey = $targetRole . ':' . $targetResourceKey;
                if (isset($relations[$relationKey])) {
                    throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Operation target relation is duplicated in {$moduleKey}.");
                }
                $relations[$relationKey] = true;
            }
            if ($cardinality === 'none' && $targetTypes !== []) {
                throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Target-free operation declares target relations in {$moduleKey}.");
            }
            if ($cardinality !== 'none' && $targetTypes === []) {
                throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Targeted operation has no target relations in {$moduleKey}.");
            }
            foreach ($operation['conditions'] ?? [] as $condition) {
                if (!is_array($condition)) {
                    throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid operation condition in {$moduleKey}.");
                }
                $this->assertAccessibleOwner(
                    $this->arrayString($condition, 'key', $moduleKey),
                    'data condition',
                    $moduleKey,
                    $dependencies,
                    $conditionOwners,
                );
                $selector = $condition['selector_resource_key'] ?? null;
                if ($selector !== null) {
                    if (!is_string($selector) || $selector === '') {
                        throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid condition selector in {$moduleKey}.");
                    }
                    $this->assertAccessibleOwner(
                        $selector,
                        'target type',
                        $moduleKey,
                        $dependencies,
                        $targetOwners,
                    );
                }
            }
        }
    }

    private function assertContract(string $class, string $contract): void
    {
        if (!$this->contractInspector->implements($class, $contract)) {
            throw new ModuleException('MODULE_CONTRACT_MISSING', "{$class} must implement {$contract}.");
        }
    }

    private function assertOwnedContract(string $class, string $contract, string $moduleKey): void
    {
        if (!str_starts_with($class, $this->layout->backendNamespace(ModuleKey::fromString($moduleKey)))) {
            throw new ModuleException(
                'MODULE_CONTRACT_MISSING',
                "{$class} must be implemented inside its owning module {$moduleKey}.",
            );
        }
        $this->assertContract($class, $contract);
    }

    /**
     * @param array<string, true> $dependencies
     * @param array<string, string> $owners
     */
    private function assertAccessibleOwner(
        string $key,
        string $kind,
        string $moduleKey,
        array $dependencies,
        array $owners,
    ): void {
        $owner = $owners[$key] ?? null;
        if ($owner === null) {
            throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Unknown {$kind} {$key} in {$moduleKey}.");
        }
        if ($owner !== 'core' && $owner !== $moduleKey && !isset($dependencies[$owner])) {
            throw new ModuleException(
                'MODULE_DEPENDENCY_MISSING',
                "{$moduleKey} references {$kind} {$key} without declaring dependency {$owner}.",
            );
        }
    }

    /** @param array<string, string> $owners */
    private function claim(array &$owners, string $key, string $moduleKey, string $kind): void
    {
        if (isset($owners[$key]) && $owners[$key] !== $moduleKey) {
            throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Duplicate {$kind} ownership: {$key}");
        }
        $owners[$key] = $moduleKey;
    }

    /**
     * @param array<string, array<string, mixed>> $menus
     * @param array<string, mixed> $menu
     * @param array<string, true> $dependencies
     * @param array<string, string> $permissionOwners
     */
    private function claimMenu(
        array &$menus,
        string $menuKey,
        string $moduleKey,
        array $menu,
        array $dependencies,
        array $permissionOwners,
    ): void {
        if (isset($menus[$menuKey])) {
            throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Duplicate menu key: {$menuKey}");
        }
        if (($menu['type'] ?? null) === 'page') {
            $component = $this->arrayString($menu, 'component_key', $moduleKey);
            if (!in_array($component, $this->frontendComponents, true)) {
                throw new ModuleException('MODULE_CONTRACT_MISSING', "Unknown frontend component: {$component}");
            }
            $this->assertAccessibleOwner(
                $this->arrayString($menu, 'required_permission', $moduleKey),
                'permission',
                $moduleKey,
                $dependencies,
                $permissionOwners,
            );
        }
        foreach ($menu['client_keys'] ?? [] as $clientKey) {
            if (!is_string($clientKey) || !in_array($clientKey, $this->registeredClientKeys, true)) {
                throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Menu {$menuKey} references an unknown Client.");
            }
        }
        $menus[$menuKey] = $menu + ['module_key' => $moduleKey];
    }

    private function string(ManifestDocument $document, string $key): string
    {
        $value = $document->data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Manifest field {$key} is required.");
        }
        return $value;
    }

    private function nestedString(ManifestDocument $document, string $group, string $key): string
    {
        $values = $document->data[$group] ?? null;
        if (!is_array($values)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Manifest group {$group} is required.");
        }
        return $this->arrayString($values, $key, $group);
    }

    /** @param array<string, mixed> $values */
    private function arrayString(array $values, string $key, string $owner): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "{$owner} field {$key} is required.");
        }
        return $value;
    }
}
