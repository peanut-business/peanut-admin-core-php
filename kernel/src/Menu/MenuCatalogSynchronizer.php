<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleException;

final readonly class MenuCatalogSynchronizer
{
    public function __construct(private MenuCatalogRepository $repository) {}

    public function synchronize(CompiledModuleRegistry $registry): void
    {
        $definitions = CoreMenuCatalog::definitions();
        foreach ($registry->menus as $menu) {
            $definitions[] = $this->definition($menu);
        }
        foreach ($definitions as $definition) {
            $this->repository->synchronize($definition, $registry->revision);
        }
        $this->repository->retireMissing(array_map(
            static fn(MenuDefinition $definition): string => $definition->key,
            $definitions,
        ));
    }

    /** @param array<string, mixed> $menu */
    private function definition(array $menu): MenuDefinition
    {
        $clients = $menu['client_keys'] ?? null;
        if (!is_array($clients) || !array_is_list($clients)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', 'Menu client keys are invalid.');
        }

        return new MenuDefinition(
            $this->requiredString($menu, 'key'),
            $this->requiredString($menu, 'module_key'),
            $this->requiredString($menu, 'scope'),
            $this->optionalString($menu, 'parent_key'),
            $this->requiredString($menu, 'type'),
            $this->requiredString($menu, 'name'),
            $this->optionalString($menu, 'route_name'),
            $this->optionalString($menu, 'route_path'),
            $this->optionalString($menu, 'component_key'),
            $this->optionalString($menu, 'required_permission'),
            array_map('strval', $clients),
            (int) ($menu['sort_order'] ?? 0),
            $this->optionalString($menu, 'icon'),
        );
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Menu field {$key} is required.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function optionalString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Menu field {$key} is invalid.");
        }

        return $value;
    }
}
