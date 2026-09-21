<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class ModuleBoundaryChecker
{
    /** @var non-empty-list<string> */
    private array $managedTablePrefixes;

    /** @param non-empty-list<string> $managedTablePrefixes */
    public function __construct(
        private CompiledModuleRegistry $registry,
        private ModuleHostLayout $layout,
        array $managedTablePrefixes,
    ) {
        foreach ($managedTablePrefixes as $prefix) {
            if (preg_match('/^[a-z][a-z0-9_]*_$/D', $prefix) !== 1) {
                throw new InvalidArgumentException('Invalid managed table prefix.');
            }
        }
        $this->managedTablePrefixes = array_values(array_unique($managedTablePrefixes));
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
            $this->registerNamespaceOwner(
                $namespaceOwners,
                $this->layout->historicalBackendNamespace($key),
                $moduleKey,
            );
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
        $declaredDependencies = $manifest->data['dependencies'] ?? [];
        if (is_array($declaredDependencies)) {
            foreach ($declaredDependencies as $dependency) {
                if (is_array($dependency) && is_string($dependency['module_key'] ?? null)) {
                    $dependencies[$dependency['module_key']] = true;
                }
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
                $namespaceOwners,
                $exportOwners,
            );
        }
    }

    /**
     * @param array<string, true> $dependencies
     * @param array<string, string> $namespaceOwners
     * @param array<string, string> $exportOwners
     */
    private function checkPhpFile(
        string $path,
        string $moduleKey,
        array $dependencies,
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
                // Registered package prefixes can live outside the legacy host namespace root.
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
                        $exportOwners,
                    );
                }
            }
            if (!in_array($type, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            foreach ($this->tableCandidates($text) as $table) {
                $owner = $this->registry->ownedTableOwners[$table] ?? null;
                if ($owner !== $moduleKey && !$this->isDeclaredForeignKeyReference(
                    $path,
                    $text,
                    $table,
                    $owner,
                    $dependencies,
                )) {
                    throw new ModuleException(
                        'MODULE_REGISTRY_CONFLICT',
                        "{$path} references table {$table} owned by " . ($owner ?? 'no registered module') . '.',
                    );
                }
            }
        }
    }

    /**
     * @param array<string, true> $dependencies
     * @param array<string, string> $exportOwners
     */
    private function assertCrossModuleContract(
        string $path,
        string $reference,
        string $moduleKey,
        ?string $owner,
        array $dependencies,
        array $exportOwners,
    ): void {
        if ($owner === null || $owner === $moduleKey
            || (!$this->layout->hasExplicitBackendNamespace(ModuleKey::fromString($owner))
                && !str_contains($reference, '\\contracts\\'))) {
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

    /** @param array<string, true> $dependencies */
    private function isDeclaredForeignKeyReference(
        string $path,
        string $literal,
        string $table,
        ?string $owner,
        array $dependencies,
    ): bool
    {
        $normalizedPath = strtolower(str_replace('\\', '/', $path));
        $referencePattern = '/\bREFERENCES\s+`' . preg_quote($table, '/') . '`/i';
        $withoutDeclaredReferences = preg_replace($referencePattern, '', $literal);

        return $owner !== null
            && isset($dependencies[$owner])
            && str_contains($normalizedPath, '/database/')
            && is_string($withoutDeclaredReferences)
            && $withoutDeclaredReferences !== $literal
            && preg_match(
                '/(?<![a-z0-9_])' . preg_quote($table, '/') . '(?![a-z0-9_])/i',
                $withoutDeclaredReferences,
            ) !== 1;
    }

    /** @return list<string> */
    private function tableCandidates(string $literal): array
    {
        $prefixPattern = implode('|', array_map(
            static fn(string $prefix): string => preg_quote($prefix, '/'),
            $this->managedTablePrefixes,
        ));
        preg_match_all(
            '/(?<![a-z0-9_])(?:' . $prefixPattern . ')[a-z0-9_]*[a-z0-9](?![a-z0-9_])/D',
            $literal,
            $matches,
        );

        return array_values(array_unique($matches[0] ?? []));
    }
}
