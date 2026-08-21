<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Menu;

use PeanutAdmin\Kernel\Menu\MenuDefinition;
use PeanutAdmin\Kernel\Menu\MenuRegistry;
use PeanutAdmin\Kernel\Module\ModuleException;
use PHPUnit\Framework\TestCase;

final class MenuRegistryTest extends TestCase
{
    public function testMenuVisibilityIsIntersectionOfClientModuleTenantPermissionAndParent(): void
    {
        $registry = new MenuRegistry([
            new MenuDefinition('example', 'example.target', 'tenant', null, 'group', 'Example', null, null, null, null, ['admin-web']),
            new MenuDefinition('example.list', 'example.target', 'tenant', 'example', 'page', 'List', 'example.list', '/example', 'example.list', 'example.read', ['admin-web']),
        ]);

        self::assertSame(['example', 'example.list'], array_map(
            static fn(MenuDefinition $menu): string => $menu->key,
            $registry->visible(
                'admin-web',
                static fn(string $module): bool => $module === 'example.target',
                static fn(string $module): bool => $module === 'example.target',
                static fn(string $permission): bool => $permission === 'example.read',
            ),
        ));
        self::assertSame([], $registry->visible('admin-web', static fn(): bool => true, static fn(): bool => false, static fn(): bool => true));
    }

    public function testUnsafeLinksAndParentCyclesAreRejected(): void
    {
        $this->expectException(ModuleException::class);
        new MenuRegistry([
            new MenuDefinition('bad', 'core', 'platform', null, 'link', 'Bad', null, 'javascript:alert(1)', null, null, ['platform-web']),
        ]);
    }
}
