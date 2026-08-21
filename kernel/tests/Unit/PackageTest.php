<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit;

use PeanutAdmin\Kernel\Package;
use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase
{
    public function testPackageIdentityIsStable(): void
    {
        self::assertSame('peanut-admin/core', Package::NAME);
        self::assertSame('0.1.0', Package::VERSION);
    }
}
