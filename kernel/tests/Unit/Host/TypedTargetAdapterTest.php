<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Host;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\DataPermissionAdapter;
use PeanutAdmin\Kernel\Authorization\PermissionRequirement;
use PeanutAdmin\Kernel\Host\ExternalOperationDefinition;
use PeanutAdmin\Kernel\Host\TypedTargetAdapter;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TypedTargetAdapterTest extends TestCase
{
    public function testQueryAndWriteUseExistingDataPermissionPort(): void
    {
        $queryConstraint = new stdClass();
        $queryCalls = 0;
        $writeCalls = 0;
        $adapter = new TypedTargetAdapter(new DataPermissionAdapter(
            static function () use (&$queryCalls, $queryConstraint): object {
                ++$queryCalls;
                return $queryConstraint;
            },
            static function () use (&$writeCalls): void {
                ++$writeCalls;
            },
        ));

        $query = $adapter->authorize(
            self::definition('query', 'many_readable'),
            $this->context(),
            [[
                'target_resource_key' => 'fixture.scope',
                'target_ids' => ['2', '1', '1'],
                'target_role' => 'primary',
            ]],
        );
        self::assertSame($queryConstraint, $query->queryConstraint);
        self::assertSame(['1', '2'], $query->targets[0]->targetIds);

        $write = $adapter->authorize(
            self::definition('targets', 'one_required'),
            $this->context(),
            [[
                'target_resource_key' => 'fixture.scope',
                'target_id' => '1',
                'target_role' => 'primary',
            ]],
        );
        self::assertNull($write->queryConstraint);
        self::assertSame(['1'], $write->targets[0]->targetIds);
        self::assertSame(1, $queryCalls);
        self::assertSame(1, $writeCalls);
    }

    public function testWrongCardinalityFailsBeforeDataAuthorization(): void
    {
        $calls = 0;
        $adapter = new TypedTargetAdapter(new DataPermissionAdapter(
            static fn(): object => new stdClass(),
            static function () use (&$calls): void {
                ++$calls;
            },
        ));

        try {
            $adapter->authorize(self::definition('targets', 'one_required'), $this->context(), []);
        } catch (ApiException $exception) {
            self::assertSame('VALIDATION_FAILED', $exception->errorCode);
            self::assertSame(0, $calls);
            return;
        }

        self::fail('Missing target must be rejected.');
    }

    private static function definition(string $mode, string $cardinality): ExternalOperationDefinition
    {
        return new ExternalOperationDefinition(
            'fixtureOperation',
            $mode === 'query' ? 'GET' : 'POST',
            '/api/v1/fixture/records',
            'tenant',
            'fixture.record',
            new PermissionRequirement('tenant', ['fixture.record.read']),
            'fixture.record',
            $mode,
            $cardinality,
            $mode !== 'query',
            $mode !== 'query',
        );
    }

    private function context(): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            'session_fixture',
            10,
            20,
            30,
            'fixture-web',
            new DateTimeImmutable('2026-07-19T00:00:00Z'),
            1,
        ), 'req_fixture_0001');
    }
}
