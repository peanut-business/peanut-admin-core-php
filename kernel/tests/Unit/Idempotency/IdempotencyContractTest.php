<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Idempotency;

use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Idempotency\CanonicalRequestHasher;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;
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
}
