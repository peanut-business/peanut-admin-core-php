<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Unit;

use PeanutAdmin\DataPermission\Package;
use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase
{
    public function testPackageIdentityIsStable(): void
    {
        self::assertSame('peanut-admin/core', Package::NAME);
        self::assertSame('0.1.0', Package::VERSION);
    }
}
