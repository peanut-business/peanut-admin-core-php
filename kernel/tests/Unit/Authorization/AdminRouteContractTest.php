<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Authorization;

use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\Etag;
use PHPUnit\Framework\TestCase;

final class AdminRouteContractTest extends TestCase
{
    public function testEveryAdminRouteUsesTheFixedPermissionBinding(): void
    {
        $root = dirname(__DIR__, 6);
        $tenantRoutes = require $root . '/backend/route/tenant-admin.php';
        $platformRoutes = require $root . '/backend/route/platform-admin.php';
        $permissionConfig = require $root . '/backend/config/permission.php';

        foreach ($tenantRoutes as $route => $binding) {
            self::assertSame($permissionConfig['tenant_routes'][$route] ?? null, $binding[2]);
        }
        foreach ($platformRoutes as $route => $binding) {
            self::assertSame($permissionConfig['platform_routes'][$route] ?? null, $binding[2]);
        }

        self::assertSame(
            'core.member.effective-access.read',
            $tenantRoutes['GET /api/v1/members/{member_id}/effective-access'][2] ?? null,
        );
    }

    public function testEtagParserRequiresTheExactRevisionFormat(): void
    {
        self::assertSame(12, Etag::parse('"rev-12"'));

        try {
            Etag::parse(null);
            self::fail('Missing If-Match must fail.');
        } catch (AdminAccessException $exception) {
            self::assertSame(428, $exception->httpStatus);
        }

        $this->expectException(AdminAccessException::class);
        Etag::parse('rev-12');
    }
}
