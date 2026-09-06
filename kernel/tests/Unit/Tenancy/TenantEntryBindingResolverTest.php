<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Tenancy;

use DomainException;
use PDO;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;
use PHPUnit\Framework\TestCase;

final class TenantEntryBindingResolverTest extends TestCase
{
    public function testDisabledBindingsUseStandaloneFallbackWithoutBindingSchema(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $request = new class {
            public function host(): string
            {
                return 'admin.example.test';
            }
        };
        $resolver = new TenantEntryBindingResolver(
            $pdo,
            static fn(string $actor, string $operation, string $operationId): TenantSystemContext =>
                new TenantSystemContext(7, $actor, $operation, $operationId),
            false,
        );

        self::assertSame('default', $resolver->loginTenantCode(
            $request,
            TenantEntryBindingResolver::ADMIN_CLIENT,
            'default',
        ));
        self::assertNull($resolver->boundTenantId($request, TenantEntryBindingResolver::ADMIN_CLIENT));
        self::assertSame(
            7,
            $resolver->system(
                $request,
                TenantEntryBindingResolver::MEMBER_CLIENT,
                'fixture',
                'fixture.read',
                'fixture-operation',
            )->tenantId,
        );
    }

    public function testEnabledBindingsStillFailClosedWithoutBindingSchema(): void
    {
        $resolver = new TenantEntryBindingResolver(new PDO('sqlite::memory:'));
        $request = new class {
            public function host(): string
            {
                return 'admin.example.test';
            }
        };

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('TENANT_ENTRY_BINDING_UNAVAILABLE');
        $resolver->boundTenantId($request, TenantEntryBindingResolver::ADMIN_CLIENT);
    }
}
