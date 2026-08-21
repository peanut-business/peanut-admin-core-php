<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use InvalidArgumentException;

final readonly class ModuleHostLayout
{
    private string $backendRoot;
    private string $backendNamespaceRoot;
    private string $frontendRoot;

    public function __construct(
        string $backendRoot,
        string $backendNamespaceRoot,
        string $frontendRoot,
    ) {
        $this->backendRoot = $this->relativeRoot($backendRoot, 'backend');
        $this->frontendRoot = $this->relativeRoot($frontendRoot, 'frontend');
        $this->backendNamespaceRoot = $this->namespaceRoot($backendNamespaceRoot);
    }

    public function backendRelativePath(ModuleKey $key): string
    {
        return $this->backendRoot . '/' . implode('/', $key->pascalSegments()) . '/';
    }

    public function backendNamespace(ModuleKey $key): string
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
