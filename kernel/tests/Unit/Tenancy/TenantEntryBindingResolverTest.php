<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Tenancy;

use DomainException;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingLookup;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TenantEntryBindingResolverTest extends TestCase
{
    /** @return iterable<string,array{bool}> */
    public static function requestKinds(): iterable
    {
        yield 'generic host' => [false];
        // Hosts also run this contract against their locked, real framework dependency.
        if (class_exists(\think\Request::class)) {
            yield 'native ThinkPHP request' => [true];
        }
    }

    private static function request(bool $native): object
    {
        if ($native) {
            return (new \think\Request())->withServer(['HTTP_HOST' => 'admin.example.test']);
        }
        return new class {
            public string $hostValue = 'admin.example.test';
            public function host(): string
            {
                return $this->hostValue;
            }
        };
    }

    #[DataProvider('requestKinds')]
    public function testBindingHitsAndMissesRespectRequestAndLookupIdentity(bool $native): void
    {
        foreach ([['tenant_id' => 7, 'tenant_code' => 'default'], null] as $binding) {
            $lookup = new class($binding) implements TenantEntryBindingLookup {
                public int $calls = 0;
                public function __construct(private ?array $result) {}
                public function binding(string $host, string $clientKey): ?array
                {
                    ++$this->calls;
                    return $this->result;
                }
            };
            $request = self::request($native);
            $resolver = new TenantEntryBindingResolver(lookup: $lookup);
            self::assertSame($binding['tenant_id'] ?? null, $resolver->boundTenantId($request, 'admin-web'));
            self::assertSame('default', $resolver->loginTenantCode($request, 'admin-web', 'default'));
            self::assertSame($native ? 1 : 2, $lookup->calls);

            // Resolver instances share only the same source on the same request.
            (new TenantEntryBindingResolver(lookup: $lookup))->boundTenantId($request, 'admin-web');
            self::assertSame($native ? 1 : 3, $lookup->calls);
            $resolver->boundTenantId(self::request($native), 'admin-web');
            self::assertSame($native ? 2 : 4, $lookup->calls);

            $otherLookup = clone $lookup;
            $otherLookup->calls = 0;
            (new TenantEntryBindingResolver(lookup: $otherLookup))->boundTenantId($request, 'admin-web');
            self::assertSame(1, $otherLookup->calls);
            $resolver->boundTenantId($request, 'member-api');
            self::assertSame($native ? 3 : 5, $lookup->calls);
        }
    }

    #[DataProvider('requestKinds')]
    public function testLookupFailuresAreNotCached(bool $native): void
    {
        $lookup = new class implements TenantEntryBindingLookup {
            public int $calls = 0;
            public function binding(string $host, string $clientKey): ?array
            {
                if (++$this->calls === 1) {
                    throw new DomainException('LOOKUP_FAILED');
                }
                return ['tenant_id' => 7, 'tenant_code' => 'default'];
            }
        };
        $resolver = new TenantEntryBindingResolver(lookup: $lookup);
        $request = self::request($native);
        try {
            $resolver->boundTenantId($request, 'admin-web');
            self::fail('Lookup failure must propagate');
        } catch (DomainException $exception) {
            self::assertSame('LOOKUP_FAILED', $exception->getMessage());
        }
        self::assertSame(7, $resolver->boundTenantId($request, 'admin-web'));
        self::assertSame(2, $lookup->calls);
    }

    #[DataProvider('requestKinds')]
    public function testCachedBindingStillRejectsConflictingTenant(bool $native): void
    {
        $lookup = new class implements TenantEntryBindingLookup {
            public function binding(string $host, string $clientKey): ?array
            {
                return ['tenant_id' => 7, 'tenant_code' => 'default'];
            }
        };
        $resolver = new TenantEntryBindingResolver(lookup: $lookup);
        $request = self::request($native);
        self::assertSame(7, $resolver->boundTenantId($request, 'admin-web'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('TENANT_ENTRY_BINDING_CONFLICT');
        $resolver->assertTenantAccess($request, 'admin-web', 8);
    }

    #[DataProvider('requestKinds')]
    public function testHostChangesAndInvalidClientsDoNotReuseBinding(bool $native): void
    {
        $lookup = new class implements TenantEntryBindingLookup {
            public array $hosts = [];
            public function binding(string $host, string $clientKey): ?array
            {
                $this->hosts[] = $host;
                return ['tenant_id' => count($this->hosts), 'tenant_code' => 'default'];
            }
        };
        $resolver = new TenantEntryBindingResolver(lookup: $lookup);
        $request = self::request($native);
        self::assertSame(1, $resolver->boundTenantId($request, 'admin-web'));
        if ($native) {
            $request->withServer(['HTTP_HOST' => 'second.example.test']);
        } else {
            $request->hostValue = 'second.example.test';
        }
        self::assertSame(2, $resolver->boundTenantId($request, 'admin-web'));
        self::assertSame(['admin.example.test', 'second.example.test'], $lookup->hosts);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('TENANT_ENTRY_CLIENT_INVALID');
        $resolver->boundTenantId($request, 'INVALID');
    }

    #[DataProvider('requestKinds')]
    public function testDisabledBindingsUseStandaloneFallbackWithoutBindingSchema(bool $native): void
    {
        $request = self::request($native);
        $resolver = new TenantEntryBindingResolver(
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

    #[DataProvider('requestKinds')]
    public function testEnabledBindingsStillFailClosedWithoutBindingSchema(bool $native): void
    {
        $resolver = new TenantEntryBindingResolver();
        $request = self::request($native);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('TENANT_ENTRY_BINDING_UNAVAILABLE');
        $resolver->boundTenantId($request, TenantEntryBindingResolver::ADMIN_CLIENT);
    }
}
