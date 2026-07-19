<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Host;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Authorization\PermissionRequirement;
use PeanutAdmin\Kernel\Host\ExternalOperationDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalOperationDefinitionTest extends TestCase
{
    public function testDefinitionMatchesConcreteRequestPath(): void
    {
        $definition = new ExternalOperationDefinition(
            'fixtureRecordUpdate',
            'PATCH',
            '/api/v1/fixture/records/{record_id}',
            'tenant',
            'fixture.record',
            new PermissionRequirement('tenant', ['fixture.record.update']),
            'fixture.record',
            'targets',
            'one_required',
            true,
            true,
        );

        self::assertTrue($definition->matches('PATCH', '/api/v1/fixture/records/42'));
        self::assertFalse($definition->matches('GET', '/api/v1/fixture/records/42'));
        self::assertFalse($definition->matches('PATCH', '/api/v1/fixture/records/42/status'));
    }

    /** @param array<int, mixed> $arguments */
    #[DataProvider('invalidDefinitions')]
    public function testContradictoryDefinitionsAreRejected(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExternalOperationDefinition(...$arguments);
    }

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function invalidDefinitions(): iterable
    {
        $tenantPermission = new PermissionRequirement('tenant', ['fixture.record.read']);
        $platformPermission = new PermissionRequirement('platform', ['platform.fixture.read']);

        yield 'audience mismatch' => [[
            'fixtureList', 'GET', '/api/v1/fixture/records', 'tenant', 'fixture.record', $platformPermission,
            'fixture.record', 'query', 'many_readable', false, false,
        ]];
        yield 'read cannot be atomic' => [[
            'fixtureList', 'GET', '/api/v1/fixture/records', 'tenant', 'fixture.record', $tenantPermission,
            'fixture.record', 'query', 'many_readable', true, true,
        ]];
        yield 'idempotency requires atomic command' => [[
            'fixtureList', 'GET', '/api/v1/fixture/records', 'tenant', 'fixture.record', $tenantPermission,
            'fixture.record', 'query', 'many_readable', false, true,
        ]];
        yield 'query requires resource' => [[
            'fixtureList', 'GET', '/api/v1/fixture/records', 'tenant', 'fixture.record', $tenantPermission,
            null, 'query', 'many_readable', false, false,
        ]];
        yield 'platform cannot request target authorization' => [[
            'fixtureList', 'GET', '/api/platform/v1/fixture/records', 'platform', 'fixture.record', $platformPermission,
            'fixture.record', 'query', 'many_readable', false, false,
        ]];
    }
}
