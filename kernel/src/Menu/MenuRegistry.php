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
        $visible = [];
        foreach ($this->byKey as $key => $definition) {
            if (!in_array($clientKey, $definition->clientKeys, true)) {
                continue;
            }
            if ($definition->moduleKey !== 'core' && (!$deploymentAvailable($definition->moduleKey) || !$tenantAvailable($definition->moduleKey))) {
                continue;
            }
            if ($definition->requiredPermission !== null && !$permissionGranted($definition->requiredPermission)) {
                continue;
            }
            $visible[$key] = $definition;
        }

        do {
            $changed = false;
            foreach ($visible as $key => $definition) {
                if ($definition->parentKey !== null && !isset($visible[$definition->parentKey])) {
                    unset($visible[$key]);
                    $changed = true;
                }
            }
            foreach ($visible as $key => $definition) {
                if ($definition->type === 'group' && !$this->hasVisibleChild($key, $visible)) {
                    unset($visible[$key]);
                    $changed = true;
                }
            }
        } while ($changed);

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

    /** @param array<string, MenuDefinition> $visible */
    private function hasVisibleChild(string $key, array $visible): bool
    {
        foreach ($visible as $definition) {
            if ($definition->parentKey === $key) {
                return true;
            }
        }
        return false;
    }
}
