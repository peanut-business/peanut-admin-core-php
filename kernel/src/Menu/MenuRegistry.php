<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

use PeanutAdmin\Kernel\Module\ModuleException;

final class MenuRegistry
{
    /** @var array<string, MenuDefinition> */
    private array $byKey = [];

    /**
     * @param list<MenuDefinition> $definitions
     * @param list<string> $linkAllowlist
     */
    public function __construct(array $definitions, private readonly array $linkAllowlist = [])
    {
        foreach ($definitions as $definition) {
            if (isset($this->byKey[$definition->key])) {
                throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Duplicate menu: {$definition->key}");
            }
            $this->assertDefinition($definition);
            $this->byKey[$definition->key] = $definition;
        }
        foreach ($this->byKey as $definition) {
            $this->assertParentChain($definition);
        }
    }

    /**
     * @param callable(string): bool $deploymentAvailable
     * @param callable(string): bool $tenantAvailable
     * @param callable(string): bool $permissionGranted
     * @return list<MenuDefinition>
     */
    public function visible(
        string $clientKey,
        callable $deploymentAvailable,
        callable $tenantAvailable,
        callable $permissionGranted,
    ): array {
        $deploymentCache = [];
        $tenantCache = [];
        $permissionCache = [];
        $visible = [];
        foreach ($this->byKey as $key => $definition) {
            if (!in_array($clientKey, $definition->clientKeys, true)) {
                continue;
            }
            if ($definition->moduleKey !== 'core') {
                $moduleKey = $definition->moduleKey;
                $deploymentCache[$moduleKey] ??= (bool) $deploymentAvailable($moduleKey);
                if (!$deploymentCache[$moduleKey]) {
                    continue;
                }
                $tenantCache[$moduleKey] ??= (bool) $tenantAvailable($moduleKey);
                if (!$tenantCache[$moduleKey]) {
                    continue;
                }
            }
            if ($definition->requiredPermission !== null) {
                $perm = $definition->requiredPermission;
                $permissionCache[$perm] ??= (bool) $permissionGranted($perm);
                if (!$permissionCache[$perm]) {
                    continue;
                }
            }
            $visible[$key] = $definition;
        }

        // First prune nodes whose ancestor is not visible (top-down memoized).
        $ancestorVisible = [];
        foreach ($visible as $key => $_) {
            if (!$this->isAncestorChainVisible($key, $visible, $ancestorVisible)) {
                unset($visible[$key]);
            }
        }

        // Then prune empty groups bottom-up using child reference counts in O(N).
        $childCount = [];
        foreach ($visible as $definition) {
            if ($definition->parentKey !== null && isset($visible[$definition->parentKey])) {
                $childCount[$definition->parentKey] = ($childCount[$definition->parentKey] ?? 0) + 1;
            }
        }
        $queue = [];
        foreach ($visible as $key => $definition) {
            if ($definition->type === 'group' && ($childCount[$key] ?? 0) === 0) {
                $queue[] = $key;
            }
        }
        while ($queue !== []) {
            $key = array_pop($queue);
            if (!isset($visible[$key])) {
                continue;
            }
            $parentKey = $visible[$key]->parentKey;
            unset($visible[$key]);
            if ($parentKey !== null && isset($visible[$parentKey])) {
                $childCount[$parentKey] = ($childCount[$parentKey] ?? 1) - 1;
                if ($visible[$parentKey]->type === 'group' && $childCount[$parentKey] <= 0) {
                    $queue[] = $parentKey;
                }
            }
        }

        $result = array_values($visible);
        usort($result, static fn(MenuDefinition $left, MenuDefinition $right): int => [$left->sortOrder, $left->key] <=> [$right->sortOrder, $right->key]);

        return $result;
    }

    private function assertDefinition(MenuDefinition $definition): void
    {
        if (!in_array($definition->scope, ['platform', 'tenant'], true)
            || !in_array($definition->type, ['group', 'page', 'link'], true)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid menu definition: {$definition->key}");
        }
        if ($definition->type === 'page'
            && ($definition->routeName === null || $definition->componentKey === null || $definition->requiredPermission === null)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Page menu is incomplete: {$definition->key}");
        }
        if ($definition->type === 'link') {
            $url = $definition->routePath ?? '';
            $parts = parse_url($url);
            $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
            $host = is_array($parts) ? ($parts['host'] ?? null) : null;
            if (!in_array($scheme, ['https'], true) || !is_string($host) || ($this->linkAllowlist !== [] && !in_array($host, $this->linkAllowlist, true))) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "Unsafe menu link: {$definition->key}");
            }
        }
    }

    private function assertParentChain(MenuDefinition $definition): void
    {
        $seen = [$definition->key => true];
        $parent = $definition->parentKey;
        while ($parent !== null) {
            if (isset($seen[$parent])) {
                throw new ModuleException('MODULE_REGISTRY_CONFLICT', "Menu parent cycle includes {$definition->key}.");
            }
            $seen[$parent] = true;
            $parentDefinition = $this->byKey[$parent] ?? throw new ModuleException(
                'MODULE_REGISTRY_CONFLICT',
                "Menu parent does not exist: {$parent}",
            );
            if ($parentDefinition->scope !== $definition->scope) {
                throw new ModuleException('MODULE_REGISTRY_CONFLICT', 'Menu parent scope mismatch.');
            }
            $parent = $parentDefinition->parentKey;
        }
    }

    /**
     * @param array<string, MenuDefinition> $visible
     * @param array<string, bool> $memo
     */
    private function isAncestorChainVisible(string $key, array $visible, array &$memo): bool
    {
        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }
        if (!isset($visible[$key])) {
            return $memo[$key] = false;
        }
        $parentKey = $visible[$key]->parentKey;
        if ($parentKey === null) {
            return $memo[$key] = true;
        }

        return $memo[$key] = $this->isAncestorChainVisible($parentKey, $visible, $memo);
    }
}
