<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

final readonly class ModuleRegistryCompiler
{
    /** @param list<string> $frontendComponents */
    public function __construct(
        private ManifestSchemaValidator $schemaValidator,
        private VersionConstraintMatcher $versionMatcher,
        private ContractInspector $contractInspector,
        private string $kernelVersion,
        private array $frontendComponents,
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
            if (!str_starts_with($provider, $moduleKey->backendNamespace())
                || !$this->contractInspector->implements($provider, ModuleProvider::class)) {
                throw new ModuleException('MODULE_CONTRACT_MISSING', "Invalid ModuleProvider for {$key}.");
            }
            $byKey[$key] = $document;
        }

        $ordered = $this->topologicalOrder($byKey);
        $targetOwners = [];
        $tableOwners = [];
        $menus = [];
        foreach ($ordered as $document) {
            $key = $this->string($document, 'key');
            $catalog = $this->catalog($document);
            foreach ($catalog['target_types'] ?? [] as $target) {
                $targetKey = $this->arrayString($target, 'key', $key);
                $this->claim($targetOwners, $targetKey, $key, 'target type');
                $this->assertContract($this->arrayString($target, 'resolver', $key), 'TargetResolver');
                $this->assertContract($this->arrayString($target, 'catalog_provider', $key), 'TargetCatalogProvider');
            }
            foreach ($catalog['protected_resources'] ?? [] as $resource) {
                $this->validateProtectedResource($resource, $key, $targetOwners);
            }
            foreach ($catalog['menus'] ?? [] as $menu) {
                $menuKey = $this->arrayString($menu, 'key', $key);
                $this->claimMenu($menus, $menuKey, $key, $menu);
            }
            $database = is_array($document->data['database'] ?? null) ? $document->data['database'] : [];
            foreach ($database['owned_tables'] ?? [] as $table) {
                if (!is_string($table) || preg_match('/^pa_[a-z0-9_]+$/D', $table) !== 1) {
                    throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid owned table in {$key}.");
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

    /** @return array<string, list<array<string, mixed>>> */
    private function catalog(ManifestDocument $document): array
    {
        $catalog = $document->data['catalog'] ?? [];
        return is_array($catalog) ? $catalog : [];
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, string> $targetOwners
     */
    private function validateProtectedResource(array $resource, string $moduleKey, array $targetOwners): void
    {
        $ownership = $this->arrayString($resource, 'ownership', $moduleKey);
        $this->assertContract($this->arrayString($resource, 'provider', $moduleKey), 'ResourceQueryPolicyProvider');
        if ($ownership === 'shared_master') {
            $scopeProvider = $resource['scope_provider'] ?? null;
            if (!is_string($scopeProvider) || $scopeProvider === '') {
                throw new ModuleException('MODULE_CONTRACT_MISSING', "shared_master in {$moduleKey} requires a scope provider.");
            }
            $this->assertContract($scopeProvider, 'SharedMasterScopeProvider');
        }
        $operations = $resource['operations'] ?? [];
        if (!is_array($operations) || !array_is_list($operations)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid operations in {$moduleKey}.");
        }
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid operation in {$moduleKey}.");
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
            foreach ($targetTypes as $targetType) {
                if (!is_string($targetType) || !isset($targetOwners[$targetType])) {
                    throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Operation references unknown target type in {$moduleKey}.");
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
     */
    private function claimMenu(array &$menus, string $menuKey, string $moduleKey, array $menu): void
    {
        if (isset($menus[$menuKey])) {
            throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Duplicate menu key: {$menuKey}");
        }
        if (($menu['type'] ?? null) === 'page') {
            $component = $this->arrayString($menu, 'component_key', $moduleKey);
            if (!in_array($component, $this->frontendComponents, true)) {
                throw new ModuleException('MODULE_CONTRACT_MISSING', "Unknown frontend component: {$component}");
            }
            $this->arrayString($menu, 'required_permission', $moduleKey);
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
