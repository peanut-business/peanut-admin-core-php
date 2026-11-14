<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class ModuleBoundaryChecker
{
    /** @param list<string> $managedTablePrefixes Kept for constructor signature compatibility. */
    public function __construct(
        private CompiledModuleRegistry $registry,
        private ModuleHostLayout $layout,
        array $managedTablePrefixes = [],
    ) {
        foreach ($managedTablePrefixes as $prefix) {
            if (preg_match('/^[a-z][a-z0-9_]*_$/D', $prefix) !== 1) {
                throw new InvalidArgumentException('Invalid managed table prefix.');
            }
        }
    }

    public function check(): void
    {
        $namespaceOwners = [];
        $exportOwners = [];
        foreach ($this->registry->modules as $manifest) {
            $moduleKey = $manifest->data['key'] ?? null;
            if (!is_string($moduleKey)) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', 'Module key is required for boundary checks.');
            }
            $key = ModuleKey::fromString($moduleKey);
            $this->registerNamespaceOwner($namespaceOwners, $this->layout->backendNamespace($key), $moduleKey);
            $contracts = is_array($manifest->data['contracts'] ?? null) ? $manifest->data['contracts'] : [];
            foreach ($contracts['exports'] ?? [] as $contract) {
                if (is_string($contract)) {
                    $name = ltrim($contract, '\\');
                    $existingOwner = $exportOwners[$name] ?? null;
                    if ($existingOwner !== null && $existingOwner !== $moduleKey) {
                        throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Contract {$name} has multiple owners.");
                    }
                    $exportOwners[$name] = $moduleKey;
                }
            }
        }
        // Declared, absent modules may still appear in cold-loadable type declarations.
        // Register only known package declarations; an absent optional package
        // has no namespace to infer and does not become a required dependency.
        foreach ($this->registry->modules as $manifest) {
            foreach ($manifest->data['optional_dependencies'] ?? [] as $dependency) {
                $key = ModuleKey::fromString($dependency['module_key']);
                if ($this->layout->hasExplicitBackendNamespace($key)) {
                    $this->registerNamespaceOwner($namespaceOwners, $this->layout->backendNamespace($key), $key->value());
                }
            }
        }
        uksort($namespaceOwners, static function (string $left, string $right): int {
            $lengthOrder = strlen($right) <=> strlen($left);

            return $lengthOrder !== 0 ? $lengthOrder : strcmp($left, $right);
        });

        foreach ($this->registry->modules as $manifest) {
            $this->checkModule($manifest, $namespaceOwners, $exportOwners);
        }
    }

    /**
     * @param array<string, string> $namespaceOwners
     * @param array<string, string> $exportOwners
     */
    private function checkModule(ManifestDocument $manifest, array $namespaceOwners, array $exportOwners): void
    {
        $moduleKey = $manifest->data['key'] ?? null;
        if (!is_string($moduleKey)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', 'Module key is required for boundary checks.');
        }
        $dependencies = [];
        $absentOptional = [];
        $declaredDependencies = $manifest->data['dependencies'] ?? [];
        if (is_array($declaredDependencies)) {
            foreach ($declaredDependencies as $dependency) {
                if (is_array($dependency) && is_string($dependency['module_key'] ?? null)) {
                    $dependencies[$dependency['module_key']] = true;
                }
            }
        }
        $selected = array_fill_keys($this->registry->moduleKeys(), true);
        foreach ($manifest->data['optional_dependencies'] ?? [] as $dependency) {
            $key = $dependency['module_key'];
            if (isset($selected[$key])) {
                $dependencies[$key] = true;
            } else {
                $absentOptional[$key] = true;
            }
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $manifest->root,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $this->checkPhpFile(
                $file->getPathname(),
                $moduleKey,
                $dependencies,
                $absentOptional,
                $namespaceOwners,
                $exportOwners,
            );
        }
    }

    /**
     * @param array<string, true> $dependencies
     * @param array<string, true> $absentOptional
     * @param array<string, string> $namespaceOwners
     * @param array<string, string> $exportOwners
     */
    private function checkPhpFile(
        string $path,
        string $moduleKey,
        array $dependencies,
        array $absentOptional,
        array $namespaceOwners,
        array $exportOwners,
    ): void {
        $tokens = token_get_all((string) file_get_contents($path));
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            [$type, $text] = $token;
            if (in_array($type, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $reference = ltrim($text, '\\');
                $referenceOwner = $this->namespaceOwner($reference, $namespaceOwners);
                // Registered package prefixes can live outside the host's reserved module root.
                // Their cross-module references still require an explicit dependency and export.
                if (($referenceOwner !== null
                        || $this->isWithinNamespace($reference, $this->layout->backendNamespaceRoot()))
                    && $referenceOwner !== $moduleKey) {
                    $this->assertCrossModuleContract(
                        $path,
                        $reference,
                        $moduleKey,
                        $referenceOwner,
                        $dependencies,
                        $absentOptional,
                        $exportOwners,
                    );
                }
            }
        }
    }

    /**
     * @param array<string, true> $dependencies
     * @param array<string, true> $absentOptional
     * @param array<string, string> $exportOwners
     */
    private function assertCrossModuleContract(
        string $path,
        string $reference,
        string $moduleKey,
        ?string $owner,
        array $dependencies,
        array $absentOptional,
        array $exportOwners,
    ): void {
        if ($owner !== null && isset($absentOptional[$owner])) {
            // No export/source audit is possible without the selected module. This
            // permits declarations, not runtime access; hosts must gate contributors.
            return;
        }
        if ($owner === null || $owner === $moduleKey) {
            throw new ModuleException(
                'MODULE_REGISTRY_CONFLICT',
                "{$path} imports another module outside its registered contracts API.",
            );
        }
        if (!isset($dependencies[$owner])) {
            throw new ModuleException(
                'MODULE_DEPENDENCY_MISSING',
                "{$path} imports {$reference} without declaring dependency {$owner}.",
            );
        }
        if (($exportOwners[$reference] ?? null) !== $owner) {
            throw new ModuleException(
                'MODULE_CONTRACT_MISSING',
                "{$path} imports {$reference}, which {$owner} does not export.",
            );
        }
    }

    /** @param array<string, string> $namespaceOwners */
    private function registerNamespaceOwner(array &$namespaceOwners, string $namespace, string $moduleKey): void
    {
        $normalized = strtolower($namespace);
        foreach ($namespaceOwners as $existingNamespace => $existingOwner) {
            if ($existingOwner !== $moduleKey
                && (str_starts_with($normalized, $existingNamespace)
                    || str_starts_with($existingNamespace, $normalized))) {
                throw new ModuleException(
                    'MODULE_REGISTRY_CONFLICT',
                    "Module namespace {$namespace} overlaps an owner {$existingOwner}.",
                );
            }
        }
        $namespaceOwners[$normalized] = $moduleKey;
    }

    /** @param array<string, string> $namespaceOwners */
    private function namespaceOwner(string $reference, array $namespaceOwners): ?string
    {
        $normalized = strtolower($reference) . '\\';
        foreach ($namespaceOwners as $namespace => $owner) {
            if (str_starts_with($normalized, $namespace)) {
                return $owner;
            }
        }

        return null;
    }

    private function isWithinNamespace(string $reference, string $namespace): bool
    {
        return str_starts_with(strtolower($reference) . '\\', strtolower($namespace));
    }
}
