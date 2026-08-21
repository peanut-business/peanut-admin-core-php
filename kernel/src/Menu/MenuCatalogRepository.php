<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

interface MenuCatalogRepository
{
    public function synchronize(MenuDefinition $definition, string $manifestDigest): void;

    /** @param list<string> $activeKeys */
    public function retireMissing(array $activeKeys): void;

    /** @return list<MenuDefinition> */
    public function activeDefinitions(string $scope): array;

    /** @return list<string> */
    public function activeDeploymentModules(): array;

    /** @return list<string> */
    public function activeTenantModules(int $tenantId): array;
}
