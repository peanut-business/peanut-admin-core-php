<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Module;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\ModuleKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleHostLayoutTest extends TestCase
{
    public function testExternalHostControlsModulePathsAndNamespace(): void
    {
        $layout = new ModuleHostLayout(
            'backend/app/Modules',
            'Dcs\\App\\Modules',
            'frontend/src/modules',
        );
        $key = ModuleKey::fromString('dcs.store');

        self::assertSame('backend/app/Modules/Dcs/Store/', $layout->backendRelativePath($key));
        self::assertSame('Dcs\\App\\Modules\\Dcs\\Store\\', $layout->backendNamespace($key));
        self::assertSame('frontend/src/modules/dcs-store/', $layout->frontendRelativePath($key));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidLayouts(): iterable
    {
        yield 'absolute backend root' => ['/backend/app/Modules', 'Dcs\\App\\Modules', 'frontend/src/modules'];
        yield 'traversing frontend root' => ['backend/app/Modules', 'Dcs\\App\\Modules', '../frontend/modules'];
        yield 'invalid namespace' => ['backend/app/Modules', 'Dcs-App\\Modules', 'frontend/src/modules'];
    }

    #[DataProvider('invalidLayouts')]
    public function testInvalidHostLayoutFailsClosed(
        string $backendRoot,
        string $backendNamespace,
        string $frontendRoot,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new ModuleHostLayout($backendRoot, $backendNamespace, $frontendRoot);
    }
}
