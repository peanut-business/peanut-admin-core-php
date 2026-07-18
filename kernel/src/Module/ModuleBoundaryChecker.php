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
            $namespaceOwners[$this->layout->backendNamespace(ModuleKey::fromString($moduleKey))] = $moduleKey;
            $contracts = is_array($manifest->data['contracts'] ?? null) ? $manifest->data['contracts'] : [];
            foreach ($contracts['exports'] ?? [] as $contract) {
                if (is_string($contract)) {
                    $exportOwners[ltrim($contract, '\\')] = $moduleKey;
                }
            }
        }

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
        $namespace = $this->layout->backendNamespace(ModuleKey::fromString($moduleKey));
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
                $namespace,
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
        string $moduleNamespace,
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
                if (str_starts_with($reference . '\\', $this->layout->backendNamespaceRoot())
                    && !str_starts_with($reference . '\\', $moduleNamespace)) {
                    $this->assertCrossModuleContract(
                        $path,
                        $reference,
                        $moduleKey,
                        $dependencies,
                        $namespaceOwners,
                        $exportOwners,
                    );
                }
            }
            if (!in_array($type, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            foreach ($this->tableCandidates($text) as $table) {
                $owner = $this->registry->ownedTableOwners[$table] ?? null;
                if ($owner !== $moduleKey && !$this->isDeclaredForeignKeyReference($path, $text, $table)) {
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
     * @param array<string, string> $namespaceOwners
     * @param array<string, string> $exportOwners
     */
    private function assertCrossModuleContract(
        string $path,
        string $reference,
        string $moduleKey,
        array $dependencies,
        array $namespaceOwners,
        array $exportOwners,
    ): void {
        $owner = null;
        foreach ($namespaceOwners as $namespace => $candidateOwner) {
            if (str_starts_with($reference . '\\', $namespace)) {
                $owner = $candidateOwner;
                break;
            }
        }
        if ($owner === null || $owner === $moduleKey || !str_contains($reference, '\\Contracts\\')) {
            throw new ModuleException(
                'MODULE_REGISTRY_CONFLICT',
                "{$path} imports another module outside its registered Contracts API.",
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

    private function isDeclaredForeignKeyReference(string $path, string $literal, string $table): bool
    {
        return str_contains($path, DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR)
            && str_contains($literal, "REFERENCES `{$table}`");
    }

    /** @return list<string> */
    private function tableCandidates(string $literal): array
    {
        $prefixPattern = implode('|', array_map(
            static fn(string $prefix): string => preg_quote($prefix, '/'),
            $this->managedTablePrefixes,
        ));
        preg_match_all(
            '/(?<![a-z0-9_])(?:' . $prefixPattern . ')[a-z0-9_]*(?![a-z0-9_])/D',
            $literal,
            $matches,
        );

        return array_values(array_unique($matches[0] ?? []));
    }
}
