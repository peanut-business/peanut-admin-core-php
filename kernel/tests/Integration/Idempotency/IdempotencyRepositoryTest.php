<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Idempotency;

use DateTimeImmutable;
use PDO;
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
        $comparisonTime = new DateTimeImmutable('2026-07-16T12:00:00Z');
        $expires = new DateTimeImmutable('2026-07-17T12:00:00Z');
        $key = IdempotencyKey::fromString('01KPEANUTADMIN-REQUEST-0001');

        $tenant = $repository->beginTenant($tenantId, $memberId, 'createWorkItem', $key, 'request-a', $expires, $comparisonTime);
        self::assertTrue($tenant->created);
        $repository->completeTenant($tenant->id, 201, ['data' => ['id' => '1']], 'example.work-item', '1');
        $replay = $repository->beginTenant($tenantId, $memberId, 'createWorkItem', $key, 'request-a', $expires, $comparisonTime);
        self::assertFalse($replay->created);
        self::assertSame('completed', $replay->status);
        self::assertSame(201, $replay->responseStatus);

        try {
            $repository->beginTenant($tenantId, $memberId, 'createWorkItem', $key, 'request-changed', $expires, $comparisonTime);
            self::fail('A reused idempotency key with a different request must be rejected.');
        } catch (ApiException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
            self::assertSame(409, $exception->httpStatus);
        }

        $platform = $repository->beginPlatform($operatorId, 'enableTenantModule', $key, 'request-b', $expires, $comparisonTime);
        $platformReplay = $repository->beginPlatform($operatorId, 'enableTenantModule', $key, 'request-b', $expires, $comparisonTime);
        self::assertTrue($platform->created);
        self::assertFalse($platformReplay->created);
        self::assertSame('processing', $platform->status);
        self::assertSame('processing', $platformReplay->status);
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_idempotency_record')->fetchColumn());
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM pa_platform_idempotency_record')->fetchColumn());
    }

    public function testExpiredProcessingLeaseIsNeverTakenOverAndKeepsItsHashBinding(): void
    {
        [$tenantId, $memberId] = $this->tenantMemberFixture();
        $repository = new PdoIdempotencyRepository($this->database);
        $key = IdempotencyKey::fromString('01KPEANUTADMIN-RECOVERY-0001');
        $requestHash = hash('sha256', 'recovery-request');
        $firstNow = new DateTimeImmutable('2026-07-19T10:00:00Z');
        $record = $repository->beginTenant(
            $tenantId,
            $memberId,
            'recoverCommand',
            $key,
            $requestHash,
            $firstNow->modify('+5 minutes'),
            $firstNow,
        );
        $this->database->exec("UPDATE pa_tenant_idempotency_record SET expires_at = '2026-07-19 09:59:59.000'");

        $stale = $repository->beginTenant(
            $tenantId,
            $memberId,
            'recoverCommand',
            $key,
            $requestHash,
            new DateTimeImmutable('2026-07-19T11:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:30:00Z'),
        );

        self::assertTrue($record->created);
        self::assertFalse($stale->created);
        self::assertFalse($stale->acquiredForExecution());
        self::assertSame('processing', $stale->status);
        self::assertSame(
            '2026-07-19 09:59:59.000',
            $this->query('SELECT expires_at FROM pa_tenant_idempotency_record')->fetchColumn(),
        );

        try {
            $repository->beginTenant(
                $tenantId,
                $memberId,
                'recoverCommand',
                $key,
                hash('sha256', 'different-request'),
                new DateTimeImmutable('2026-07-19T11:30:00Z'),
                new DateTimeImmutable('2026-07-19T10:31:00Z'),
            );
            self::fail('Expiry must not release the idempotency key hash binding.');
        } catch (ApiException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
        }
    }

    public function testFailedOutcomeReplaysAndDuplicateTerminalTransitionIsRejected(): void
    {
        [$tenantId, $memberId] = $this->tenantMemberFixture();
        $repository = new PdoIdempotencyRepository($this->database);
        $now = new DateTimeImmutable('2026-07-19T10:00:00Z');
        $key = IdempotencyKey::fromString('01KPEANUTADMIN-FAILED-0001');
        $record = $repository->beginTenant(
            $tenantId,
            $memberId,
            'failedCommand',
            $key,
            hash('sha256', 'failed-request'),
            $now->modify('+1 hour'),
            $now,
        );
        $repository->failTenant($record->id, 422, ['code' => 'FIXTURE_DENIED']);

        $replay = $repository->beginTenant(
            $tenantId,
            $memberId,
            'failedCommand',
            $key,
            hash('sha256', 'failed-request'),
            $now->modify('+1 hour'),
            $now,
        );
        self::assertSame('failed', $replay->status);
        self::assertTrue($replay->replayable());
        self::assertSame(422, $replay->responseStatus);

        try {
            $repository->completeTenant($record->id, 200, ['data' => ['ok' => true]]);
            self::fail('A terminal idempotency record cannot transition again.');
        } catch (ApiException $exception) {
            self::assertSame('IDEMPOTENCY_STATE_CONFLICT', $exception->errorCode);
        }
    }

    public function testInvalidExpiryIsRejectedBeforeWriting(): void
    {
        [$tenantId, $memberId] = $this->tenantMemberFixture();
        $now = new DateTimeImmutable('2026-07-19T10:00:00Z');

        $this->expectException(\InvalidArgumentException::class);
        try {
            (new PdoIdempotencyRepository($this->database))->beginTenant(
                $tenantId,
                $memberId,
                'invalidExpiryCommand',
                IdempotencyKey::fromString('01KPEANUTADMIN-EXPIRY-0001'),
                hash('sha256', 'invalid-expiry'),
                $now,
                $now,
            );
        } finally {
            self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_idempotency_record')->fetchColumn());
        }
    }

    public function testFoundRowsModeCannotCreateFalseExecutionOwnership(): void
    {
        [$tenantId, $memberId] = $this->tenantMemberFixture();
        $repository = new PdoIdempotencyRepository($this->connection(foundRows: true));
        $now = new DateTimeImmutable('2026-07-19T10:00:00Z');
        $key = IdempotencyKey::fromString('01KPEANUTADMIN-FOUND-ROWS01');
        $requestHash = hash('sha256', 'found-rows-request');

        $created = $repository->beginTenant(
            $tenantId,
            $memberId,
            'foundRowsCommand',
            $key,
            $requestHash,
            $now->modify('+1 hour'),
            $now,
        );
        $existing = $repository->beginTenant(
            $tenantId,
            $memberId,
            'foundRowsCommand',
            $key,
            $requestHash,
            $now->modify('+1 hour'),
            $now,
        );

        self::assertTrue($created->acquiredForExecution());
        self::assertFalse($existing->acquiredForExecution());
        self::assertSame('processing', $existing->status);
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_idempotency_record')->fetchColumn());
    }

    public function testNewLeaseAndCompletionRollbackWithTheOuterTransaction(): void
    {
        [$tenantId, $memberId] = $this->tenantMemberFixture();
        $repository = new PdoIdempotencyRepository($this->database);
        $now = new DateTimeImmutable('2026-07-19T10:00:00Z');

        $this->database->beginTransaction();
        $record = $repository->beginTenant(
            $tenantId,
            $memberId,
            'rollbackCommand',
            IdempotencyKey::fromString('01KPEANUTADMIN-ROLLBACK-0001'),
            hash('sha256', 'rollback-request'),
            $now->modify('+1 hour'),
            $now,
        );
        $repository->completeTenant($record->id, 200, ['data' => ['ok' => true]]);
        $this->database->rollBack();

        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_idempotency_record')->fetchColumn());
    }

    public function testConcurrentLiveLeaseHasExactlyOneOwner(): void
    {
        [$tenantId, $memberId] = $this->tenantMemberFixture();
        $results = $this->concurrentAcquisitions($tenantId, $memberId, '01KPEANUTADMIN-CONCURRENT-LIVE1');

        self::assertCount(1, array_filter($results, static fn(array $result): bool => ($result['owned'] ?? false) === true));
    }

    public function testConcurrentExpiredLeaseHasNoOwner(): void
    {
        [$tenantId, $memberId] = $this->tenantMemberFixture();
        $keyValue = '01KPEANUTADMIN-CONCURRENT-STALE';
        $requestHash = hash('sha256', 'concurrent-request');
        $firstNow = new DateTimeImmutable('2026-07-19T10:00:00Z');
        (new PdoIdempotencyRepository($this->database))->beginTenant(
            $tenantId,
            $memberId,
            'concurrentCommand',
            IdempotencyKey::fromString($keyValue),
            $requestHash,
            $firstNow->modify('+1 minute'),
            $firstNow,
        );
        $this->database->exec("UPDATE pa_tenant_idempotency_record SET expires_at = '2026-07-19 09:00:00.000'");

        $results = $this->concurrentAcquisitions($tenantId, $memberId, $keyValue);

        self::assertCount(0, array_filter($results, static fn(array $result): bool => ($result['owned'] ?? false) === true));
    }

    /** @return list<array<string, bool|string>> */
    private function concurrentAcquisitions(int $tenantId, int $memberId, string $keyValue): array
    {
        $requestHash = hash('sha256', 'concurrent-request');
        $paths = [tempnam(sys_get_temp_dir(), 'peanut-r01-a-'), tempnam(sys_get_temp_dir(), 'peanut-r01-b-')];
        self::assertIsString($paths[0]);
        self::assertIsString($paths[1]);
        $processes = [];
        foreach ($paths as $path) {
            $processId = pcntl_fork();
            self::assertNotSame(-1, $processId);
            if ($processId === 0) {
                try {
                    $pdo = $this->connection();
                    $pdo->beginTransaction();
                    $record = (new PdoIdempotencyRepository($pdo))->beginTenant(
                        $tenantId,
                        $memberId,
                        'concurrentCommand',
                        IdempotencyKey::fromString($keyValue),
                        $requestHash,
                        new DateTimeImmutable('2026-07-19T12:00:00Z'),
                        new DateTimeImmutable('2026-07-19T11:00:00Z'),
                    );
                    file_put_contents($path, json_encode([
                        'owned' => $record->acquiredForExecution(),
                        'status' => $record->status,
                    ], JSON_THROW_ON_ERROR));
                    if ($record->acquiredForExecution()) {
                        usleep(500_000);
                    }
                    $pdo->commit();
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($path, json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
                    exit(1);
                }
            }
            $processes[] = $processId;
        }

        $statuses = [];
        foreach ($processes as $processId) {
            pcntl_waitpid($processId, $status);
            $statuses[] = $status;
        }
        $this->database = $this->connection();
        $this->admin = $this->connection(null);
        $results = array_map(static function (string $path): array {
            $result = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            unlink($path);

            return $result;
        }, $paths);

        foreach ($statuses as $index => $status) {
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), (string) ($results[$index]['error'] ?? ''));
            self::assertArrayNotHasKey('error', $results[$index]);
        }

        return $results;
    }

    /** @return array{int, int} */
    private function tenantMemberFixture(): array
    {
        $this->runner->migrate();
        $now = '2026-07-19 00:00:00.000';
        $accountId = $this->insert('pa_account', [
            'display_name' => 'Idempotency Fixture', 'status' => 'active', 'security_revision' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $tenantId = $this->insert('pa_tenant', [
            'code' => 'idempotency', 'name' => 'Idempotency', 'display_name' => 'Idempotency',
            'status' => 'active', 'locale' => 'zh-CN', 'timezone' => 'Asia/Shanghai',
            'security_revision' => 1, 'authorization_revision' => 1, 'revision' => 1,
            'activated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $memberId = $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId, 'account_id' => $accountId, 'member_type' => 'internal',
            'status' => 'active', 'security_revision' => 1, 'authorization_revision' => 1,
            'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$tenantId, $memberId];
    }

    private function connection(?string $database = self::DATABASE, bool $foundRows = false): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($foundRows) {
            $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        }

        return new PDO(
            sprintf(
                'mysql:host=127.0.0.1;port=%d%s;charset=utf8mb4',
                (int) (getenv('MYSQL_PORT') ?: 3306),
                $database === null ? '' : ';dbname=' . $database,
            ),
            'root',
            getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev',
            $options,
        );
    }
}
