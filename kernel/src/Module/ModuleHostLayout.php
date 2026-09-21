<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use InvalidArgumentException;

final readonly class ModuleHostLayout
{
    private string $backendRoot;
    private string $backendNamespaceRoot;
    private string $frontendRoot;

    /** @var array<string, non-empty-string> Host-verified package namespace by module key. */
    private array $backendNamespaces;

    /**
     * Explicit namespaces come from the host's validated package declarations, never HTTP input.
     * Omission preserves the original key-derived layout for existing hosts.
     *
     * @param array<string, string> $backendNamespaces
     */
    public function __construct(
        string $backendRoot,
        string $backendNamespaceRoot,
        string $frontendRoot,
        array $backendNamespaces = [],
    ) {
        $this->backendRoot = $this->relativeRoot($backendRoot, 'backend');
        $this->frontendRoot = $this->relativeRoot($frontendRoot, 'frontend');
        $this->backendNamespaceRoot = $this->namespaceRoot($backendNamespaceRoot);
        $normalized = [];
        foreach ($backendNamespaces as $moduleKey => $namespace) {
            if (!is_string($moduleKey) || !is_string($namespace)) {
                throw new InvalidArgumentException('Invalid explicit Module namespace declaration.');
            }
            $key = ModuleKey::fromString($moduleKey)->value();
            $prefix = $this->namespaceRoot($namespace) . '\\';
            foreach ($normalized as $existingKey => $existingPrefix) {
                $left = strtolower($prefix);
                $right = strtolower($existingPrefix);
                if (str_starts_with($left, $right) || str_starts_with($right, $left)) {
                    throw new InvalidArgumentException("Module namespaces overlap: {$existingKey} and {$key}.");
                }
            }
            $normalized[$key] = $prefix;
        }
        ksort($normalized, SORT_STRING);
        $this->backendNamespaces = $normalized;
    }

    public function backendRelativePath(ModuleKey $key): string
    {
        return $this->backendRoot . '/' . implode('/', $key->snakeSegments()) . '/';
    }

    public function backendNamespace(ModuleKey $key): string
    {
        return $this->backendNamespaces[$key->value()]
            ?? $this->backendNamespaceRoot . '\\' . implode('\\', $key->snakeSegments()) . '\\';
    }

    public function hasExplicitBackendNamespace(ModuleKey $key): bool
    {
        return isset($this->backendNamespaces[$key->value()]);
    }

    public function historicalBackendNamespace(ModuleKey $key): string
    {
        return $this->backendNamespaceRoot . '\\' . implode('\\', $key->pascalSegments()) . '\\';
    }

    public function backendNamespaceRoot(): string
    {
        return $this->backendNamespaceRoot . '\\';
    }

    public function frontendRelativePath(ModuleKey $key): string
    {
        return $this->frontendRoot . '/' . $key->slug() . '/';
    }

    private function relativeRoot(string $root, string $kind): string
    {
        $root = rtrim($root, '/');
        if (
            $root === ''
            || str_starts_with($root, '/')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $root) === 1
            || preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#D', $root) !== 1
        ) {
            throw new InvalidArgumentException("Invalid {$kind} Module root.");
        }

        return $root;
    }

    private function namespaceRoot(string $namespace): string
    {
        $namespace = trim($namespace, '\\');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace) !== 1) {
            throw new InvalidArgumentException('Invalid Module namespace root.');
        }

        return $namespace;
    }
}
