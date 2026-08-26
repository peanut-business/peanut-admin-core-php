<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Authorization;

use PeanutAdmin\Kernel\Authorization\CorePermissionCatalog;
use PHPUnit\Framework\TestCase;

final class CorePermissionCatalogTest extends TestCase
{
    public function testPlatformModuleRuntimePermissionsAreRegisteredExactly(): void
    {
        self::assertSame([
            'platform.module.read',
            'platform.module.install',
            'platform.module.uninstall',
            'platform.module.disable',
            'platform.module.sync',
        ], array_values(array_filter(
            CorePermissionCatalog::PLATFORM,
            static fn(string $permission): bool => str_starts_with($permission, 'platform.module.'),
        )));
    }
}
