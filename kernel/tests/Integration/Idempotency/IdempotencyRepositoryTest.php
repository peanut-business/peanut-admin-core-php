<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Idempotency;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;
use PeanutAdmin\Kernel\Idempotency\PdoIdempotencyRepository;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class IdempotencyRepositoryTest extends DatabaseTestCase
{
    public function testTenantAndPlatformRecordsArePhysicallySeparatedAndReplaySafe(): void
    {
        $this->runner->migrate();
        $now = '2026-07-16 12:00:00.000';
        $accountId = $this->insert('pa_account', [
            'display_name' => 'Fixture', 'status' => 'active', 'security_revision' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $tenantId = $this->insert('pa_tenant', [
            'code' => 'alpha', 'name' => 'Alpha', 'display_name' => 'Alpha', 'status' => 'active',
            'locale' => 'zh-CN', 'timezone' => 'Asia/Shanghai', 'security_revision' => 1,
            'authorization_revision' => 1, 'revision' => 1, 'activated_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $memberId = $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId, 'account_id' => $accountId, 'member_type' => 'internal',
            'status' => 'active', 'security_revision' => 1, 'authorization_revision' => 1,
            'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $operatorId = $this->insert('pa_platform_operator', [
            'account_id' => $accountId, 'status' => 'active', 'security_revision' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $repository = new PdoIdempotencyRepository($this->database);
        $expires = new DateTimeImmutable('2026-07-17T12:00:00Z');
        $key = IdempotencyKey::fromString('01KPEANUTADMIN-REQUEST-0001');

        $tenant = $repository->beginTenant($tenantId, $memberId, 'createWorkItem', $key, 'request-a', $expires);
        self::assertTrue($tenant->created);
        $repository->completeTenant($tenant->id, 201, ['data' => ['id' => '1']], 'example.work-item', '1');
        $replay = $repository->beginTenant($tenantId, $memberId, 'createWorkItem', $key, 'request-a', $expires);
        self::assertFalse($replay->created);
        self::assertSame('completed', $replay->status);
        self::assertSame(201, $replay->responseStatus);

        try {
            $repository->beginTenant($tenantId, $memberId, 'createWorkItem', $key, 'request-changed', $expires);
            self::fail('A reused idempotency key with a different request must be rejected.');
        } catch (ApiException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
            self::assertSame(409, $exception->httpStatus);
        }

        $platform = $repository->beginPlatform($operatorId, 'enableTenantModule', $key, 'request-b', $expires);
        $platformReplay = $repository->beginPlatform($operatorId, 'enableTenantModule', $key, 'request-b', $expires);
        self::assertTrue($platform->created);
        self::assertFalse($platformReplay->created);
        self::assertSame('processing', $platform->status);
        self::assertSame('processing', $platformReplay->status);
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_idempotency_record')->fetchColumn());
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM pa_platform_idempotency_record')->fetchColumn());
    }
}
