<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Menu;

use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PeanutAdmin\Kernel\Menu\MenuCatalogSynchronizer;
use PeanutAdmin\Kernel\Menu\MenuDefinition;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PHPUnit\Framework\TestCase;

final class MenuCatalogSynchronizerTest extends TestCase
{
    public function testCoreAndModuleMenusAreSynchronizedAsOneCompleteCatalog(): void
    {
        $repository = new class implements MenuCatalogRepository {
            /** @var array<string, MenuDefinition> */
            public array $definitions = [];

            /** @var list<string> */
            public array $activeKeys = [];

            /** @var list<string> */
            public array $digests = [];

            public function synchronize(MenuDefinition $definition, string $manifestDigest): void
            {
                $this->digests[] = $manifestDigest;
                $this->definitions[$definition->key] = $definition;
            }

            public function retireMissing(array $activeKeys): void
            {
                $this->activeKeys = $activeKeys;
            }

            public function activeDefinitions(string $scope): array
            {
                return [];
            }

            public function activeDeploymentModules(): array
            {
                return [];
            }

            public function activeTenantModules(int $tenantId): array
            {
                return [];
            }
        };
        $registry = new CompiledModuleRegistry([], [], [], [
            'example.list' => [
                'key' => 'example.list',
                'module_key' => 'example.target',
                'scope' => 'tenant',
                'parent_key' => null,
                'type' => 'page',
                'name' => 'Example',
                'route_name' => 'example.list',
                'route_path' => '/app/example',
                'component_key' => 'example.list',
                'required_permission' => 'example.read',
                'client_keys' => ['admin-web'],
                'sort_order' => 100,
                'icon' => 'Box',
            ],
        ], 'registry-digest');

        (new MenuCatalogSynchronizer($repository))->synchronize($registry);

        self::assertCount(17, $repository->definitions);
        self::assertSame(['registry-digest'], array_values(array_unique($repository->digests)));
        self::assertSame(array_keys($repository->definitions), $repository->activeKeys);
        self::assertSame('Users', $repository->definitions['core.organization']->icon);
        self::assertSame('example.target', $repository->definitions['example.list']->moduleKey);
    }
}
