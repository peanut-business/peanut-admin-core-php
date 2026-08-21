<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Module;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleGuard;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleGuardTest extends TestCase
{
    #[DataProvider('unavailableCases')]
    public function testGuardFailsClosedBeforeBusinessAuthorization(
        ?ModuleInstallationRecord $installation,
        ?TenantModuleRecord $tenantModule,
        bool $permission,
        string $expectedCode,
    ): void {
        $guard = new ModuleGuard($this->repository($installation, $tenantModule));

        try {
            $guard->assertMemberAccess(9, 'example.target', $permission, new DateTimeImmutable('2026-07-16T12:00:00Z'));
        } catch (ModuleException $exception) {
            self::assertSame($expectedCode, $exception->errorCode);

            return;
        }

        self::fail('Expected module guard to reject access.');
    }

    /** @return iterable<string, array{?ModuleInstallationRecord, ?TenantModuleRecord, bool, string}> */
    public static function unavailableCases(): iterable
    {
        $active = new ModuleInstallationRecord('example.target', '1.0.0', 'active', 1, 'digest');
        $enabled = new TenantModuleRecord(9, 'example.target', 'enabled', null, null, 1);

        yield 'not installed' => [null, $enabled, true, 'MODULE_NOT_INSTALLED'];
        yield 'maintenance' => [
            new ModuleInstallationRecord('example.target', '1.0.0', 'maintenance', 1, 'digest'),
            $enabled,
            true,
            'MODULE_INSTALLATION_FAILED',
        ];
        yield 'tenant disabled' => [$active, null, true, 'MODULE_TENANT_DISABLED'];
        yield 'not effective' => [
            $active,
            new TenantModuleRecord(9, 'example.target', 'enabled', new DateTimeImmutable('2026-07-17T00:00:00Z'), null, 1),
            true,
            'MODULE_TENANT_NOT_EFFECTIVE',
        ];
        yield 'expired' => [
            $active,
            new TenantModuleRecord(9, 'example.target', 'enabled', null, new DateTimeImmutable('2026-07-16T11:59:59Z'), 1),
            true,
            'MODULE_TENANT_NOT_EFFECTIVE',
        ];
        yield 'permission denied' => [$active, $enabled, false, 'AUTHORIZATION_PERMISSION_DENIED'];
    }

    public function testGuardAllowsOnlyAllThreeLayersAndBoundsCacheTtl(): void
    {
        $guard = new ModuleGuard($this->repository(
            new ModuleInstallationRecord('example.target', '1.0.0', 'active', 1, 'digest'),
            new TenantModuleRecord(
                9,
                'example.target',
                'enabled',
                null,
                new DateTimeImmutable('2026-07-16T12:00:17Z'),
                1,
            ),
        ));

        $guard->assertMemberAccess(9, 'example.target', true, new DateTimeImmutable('2026-07-16T12:00:00Z'));
        self::assertSame(17, $guard->cacheTtl(9, 'example.target', new DateTimeImmutable('2026-07-16T12:00:00Z'), 60));
    }

    private function repository(
        ?ModuleInstallationRecord $installation,
        ?TenantModuleRecord $tenantModule,
    ): ModuleRuntimeRepository {
        return new class ($installation, $tenantModule) implements ModuleRuntimeRepository {
            public function __construct(
                private ?ModuleInstallationRecord $installation,
                private ?TenantModuleRecord $tenantModule,
            ) {}

            public function installation(string $moduleKey): ?ModuleInstallationRecord
            {
                return $this->installation;
            }

            public function tenantModule(int $tenantId, string $moduleKey): ?TenantModuleRecord
            {
                return $this->tenantModule;
            }

            public function enabledDependents(int $tenantId, string $moduleKey): array
            {
                return [];
            }
        };
    }
}
