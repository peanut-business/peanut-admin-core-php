<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Idempotency;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Idempotency\CanonicalRequestHasher;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;
use PeanutAdmin\Kernel\Idempotency\IdempotencyRecord;
use PeanutAdmin\Kernel\Idempotency\IdempotencyService;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PHPUnit\Framework\TestCase;
use think\Container;
use think\DbManager;

final class IdempotencyContractTest extends TestCase
{
    public function testCanonicalHashIgnoresObjectKeyOrderButKeepsTypedTargets(): void
    {
        $hasher = new CanonicalRequestHasher();
        $left = $hasher->hash('POST', '/api/v1/example/work-items', [
            'title' => 'Fixture',
            'target' => ['target_id' => '1', 'target_resource_key' => 'example.project'],
        ]);
        $right = $hasher->hash('post', '/api/v1/example/work-items', [
            'target' => ['target_resource_key' => 'example.project', 'target_id' => '1'],
            'title' => 'Fixture',
        ]);

        self::assertSame($left, $right);
        self::assertNotSame($left, $hasher->hash('POST', '/api/v1/example/work-items', [
            'title' => 'Fixture',
            'target' => ['target_id' => '1', 'target_resource_key' => 'example.queue'],
        ]));
    }

    public function testKeyIsValidatedAndOnlyItsHashIsRetained(): void
    {
        $key = IdempotencyKey::fromString('01KPEANUTADMIN-REQUEST-0001');

        self::assertSame(64, strlen($key->hash));
        self::assertStringNotContainsString('PEANUT', $key->hash);

        $this->expectException(ApiException::class);
        IdempotencyKey::fromString('short');
    }

    public function testRecordDistinguishesExecutionOwnershipAndTerminalReplay(): void
    {
        $processing = new IdempotencyRecord(
            10,
            'processing',
            'request-hash',
            null,
            null,
            null,
            null,
            false,
        );
        $created = new IdempotencyRecord(12, 'processing', 'request-hash', null, null, null, null, true);
        $failed = new IdempotencyRecord(
            11,
            'failed',
            'request-hash',
            422,
            ['code' => 'FIXTURE_DENIED'],
            null,
            null,
            false,
        );

        self::assertFalse($processing->acquiredForExecution());
        self::assertFalse($processing->replayable());
        self::assertTrue($created->acquiredForExecution());
        self::assertFalse($failed->acquiredForExecution());
        self::assertTrue($failed->replayable());
    }

    public function testRetainedCompletedReadPreservesExpiredReceiptAndItsNamespace(): void
    {
        $previous = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        try {
            $db = new DbManager();
            $db->setConfig(['default' => 'fixture', 'connections' => [
                'fixture' => ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'pa_'],
            ]]);
            $container->instance(DbManager::class, $db);
            $db->execute('CREATE TABLE pa_tenant_idempotency_record (
                id INTEGER PRIMARY KEY, tenant_id INTEGER, tenant_member_id INTEGER, operation_key TEXT,
                idempotency_key_hash TEXT, request_hash TEXT, status TEXT, expires_at TEXT,
                response_status INTEGER, response_body_json TEXT, resource_type TEXT, resource_id TEXT,
                updated_at TEXT
            )');
            $scope = TenantScope::fromTrustedContext(1, 'fixture');
            $key = IdempotencyKey::fromString('01KPEANUTADMIN-REQUEST-0001');
            $stored = ['tenant_id' => 1, 'tenant_member_id' => 2, 'operation_key' => 'fixture.write',
                'idempotency_key_hash' => $key->hash, 'request_hash' => str_repeat('a', 64),
                'status' => 'completed', 'expires_at' => '2000-01-01 00:00:00', 'response_status' => 201,
                'response_body_json' => '{"receipt":"original"}', 'resource_type' => 'fixture.item', 'resource_id' => 'fixture-1',
                'updated_at' => null];
            $db->table('pa_tenant_idempotency_record')->insert($stored);
            $service = new IdempotencyService();
            $read = static fn(TenantScope $tenant, int $member, string $operation, string $hash) =>
                $db->transaction(static fn() => $service->findRetainedCompletedTenant($tenant, $member, $operation, $key, $hash));
            self::assertNull($service->findCompletedTenant($scope, 2, 'fixture.write', $key, $stored['request_hash']));
            self::assertNull($read(TenantScope::fromTrustedContext(2, 'fixture'), 2, 'fixture.write', $stored['request_hash']));
            self::assertNull($read($scope, 3, 'fixture.write', $stored['request_hash']));
            self::assertNull($read($scope, 2, 'fixture.other', $stored['request_hash']));
            $receipt = $read($scope, 2, 'fixture.write', $stored['request_hash']);
            self::assertSame(['receipt' => 'original'], $receipt?->responseBody);
            self::assertFalse($receipt->acquiredForExecution());
            try {
                $read($scope, 2, 'fixture.write', str_repeat('b', 64));
                self::fail('A different request digest must be rejected.');
            } catch (ApiException $error) {
                self::assertSame('IDEMPOTENCY_KEY_REUSED', $error->errorCode);
            }
            self::assertSame(['id' => 1, ...$stored], $db->table('pa_tenant_idempotency_record')->where('id', 1)->find());

            $reclaimed = $db->transaction(static fn() => $service->beginTenant(
                $scope,
                2,
                'fixture.write',
                $key,
                str_repeat('b', 64),
                new DateTimeImmutable('2099-01-02T00:00:00Z'),
                new DateTimeImmutable('2099-01-01T00:00:00Z'),
            ));
            self::assertTrue($reclaimed->acquiredForExecution());
            self::assertSame('processing', $reclaimed->status);
            self::assertNull($reclaimed->responseBody);
            self::assertNull($read($scope, 2, 'fixture.write', str_repeat('b', 64)));
        } finally {
            Container::setInstance($previous);
        }
    }
}
