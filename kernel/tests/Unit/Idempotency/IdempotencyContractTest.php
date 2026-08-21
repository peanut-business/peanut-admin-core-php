<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Idempotency;

use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Idempotency\CanonicalRequestHasher;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;
use PeanutAdmin\Kernel\Idempotency\IdempotencyRecord;
use PHPUnit\Framework\TestCase;

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
}
