<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Tests\Integration\Application;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PeanutAdmin\EntitlementQuota\Application\EntitlementQuotaException;
use PeanutAdmin\EntitlementQuota\Application\EntitlementQuotaReceipt;
use PeanutAdmin\EntitlementQuota\Application\EntitlementQuotaService;
use PeanutAdmin\EntitlementQuota\Contract\EntitlementGrantSnapshot;
use PeanutAdmin\EntitlementQuota\Contract\EntitlementMeter;
use PeanutAdmin\EntitlementQuota\Contract\EntitlementMeterRegistry;
use PeanutAdmin\EntitlementQuota\Contract\EntitlementPolicyProvider;
use PeanutAdmin\EntitlementQuota\Database\Schema;
use PeanutAdmin\EntitlementQuota\Package;
use PeanutAdmin\EntitlementQuota\Persistence\PdoEntitlementQuotaRepository;
use PeanutAdmin\Kernel\Auth\Clock;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Idempotency\IdempotencySchema;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class EntitlementQuotaServiceTest extends TestCase
{
    private const DATABASE = 'peanut_admin_p1_entitlement_service_test';
    private const METER = 'records.count';
    private const TARGET_TYPE = 'resource.item';
    private const TARGET_KEY = 'item-1';

    private PDO $admin;
    private PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-ENTITLEMENT-QUOTA-001 MySQL qualification.');
        }
        $port = (int) (getenv('DB_PORT') ?: 0);
        if (getenv('DB_HOST') !== '127.0.0.1'
            || $port < 1024
            || $port > 65535
            || $port !== (int) getenv('MYSQL_PORT')) {
            throw new RuntimeException('EntitlementQuota qualification requires an explicit local MySQL port.');
        }
        $password = getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev';
        $this->admin = new PDO(
            "mysql:host=127.0.0.1;port={$port};charset=utf8mb4",
            'root',
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        $this->admin->exec('CREATE DATABASE `' . self::DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->pdo = new PDO(
            "mysql:host=127.0.0.1;port={$port};dbname=" . self::DATABASE . ';charset=utf8mb4',
            'root',
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        $this->createKernelFixtures();
        foreach (Schema::createSql() as $statement) {
            $this->pdo->exec($statement);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        }
    }

    public function testPublicSurfaceAndReceiptReplayShapeAreStable(): void
    {
        self::assertSame(
            ['check', 'reserve', 'commit', 'release', 'usage'],
            array_values(array_map(
                static fn(ReflectionMethod $method): string => $method->getName(),
                array_filter(
                    (new ReflectionClass(EntitlementQuotaService::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                    static fn(ReflectionMethod $method): bool => $method->getDeclaringClass()->getName()
                        === EntitlementQuotaService::class && $method->getName() !== '__construct',
                ),
            )),
        );
        self::assertSame('peanut.entitlement-quota', Package::MODULE_KEY);
        self::assertSame('0.1.0-alpha.7', Package::VERSION);

        $receipt = new EntitlementQuotaReceipt(
            Package::RESERVE_OPERATION,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            'record',
            'reservation_' . str_repeat('a', 32),
            2,
            'pending',
            1,
            2,
            10,
            7,
            '2030-02-01T00:00:00.000Z',
            '2030-03-01T00:00:00.000Z',
            '2030-02-15T12:00:00.000Z',
            '2030-02-15T12:05:00.000Z',
            null,
            str_repeat('b', 64),
        );
        self::assertSame(
            $receipt->toArray(),
            EntitlementQuotaReceipt::fromArray($receipt->toArray(), Package::RESERVE_OPERATION)->toArray(),
        );
    }

    public function testCheckReserveReplayCommitAndUsageUseOneAtomicAuthority(): void
    {
        [, $context] = $this->seedContext('req_entitlement_happy');
        $clock = new MutableEntitlementClock('2030-02-15T12:34:56.789Z');
        $provider = new MutableEntitlementPolicyProvider($this->snapshot(limit: 10));
        $service = $this->service($provider, $clock);
        $authorized = $this->quotaContext($context, self::TARGET_KEY);

        $check = $service->check($authorized, self::METER, self::TARGET_TYPE, self::TARGET_KEY, 4);
        self::assertTrue($check->allowed);
        self::assertSame(10, $check->remainingAmount);
        self::assertSame('2030-02-01T00:00:00.000Z', $check->windowStart);
        self::assertSame('2030-03-01T00:00:00.000Z', $check->windowEnd);

        $reserved = $service->reserve(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            4,
            'entitlement-reserve-happy-0001',
        );
        $replay = $service->reserve(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            4,
            'entitlement-reserve-happy-0001',
        );
        self::assertSame($reserved->toArray(), $replay->toArray());
        self::assertSame('pending', $reserved->state);
        self::assertSame(4, $reserved->reservedAmount);
        self::assertSame(6, $reserved->remainingAmount);

        $pendingUsage = $service->usage($authorized, self::METER, self::TARGET_TYPE, self::TARGET_KEY);
        self::assertSame(0, $pendingUsage->committedAmount);
        self::assertSame(4, $pendingUsage->reservedAmount);

        $committed = $service->commit(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            $reserved->reservationKey,
            'entitlement-commit-happy-0001',
        );
        $commitReplay = $service->commit(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            $reserved->reservationKey,
            'entitlement-commit-happy-0001',
        );
        self::assertSame($committed->toArray(), $commitReplay->toArray());
        self::assertSame('committed', $committed->state);
        self::assertSame(4, $committed->committedAmount);
        self::assertSame(0, $committed->reservedAmount);

        $usage = $service->usage($authorized, self::METER, self::TARGET_TYPE, self::TARGET_KEY);
        self::assertSame(4, $usage->committedAmount);
        self::assertSame(0, $usage->reservedAmount);
        self::assertSame(6, $usage->remainingAmount);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_entitlement_grant')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_entitlement_policy_revision')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_entitlement_usage_window')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_entitlement_reservation')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_entitlement_usage_ledger')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_idempotency_record')->fetchColumn());

        $audit = (string) $this->pdo->query(
            'SELECT JSON_ARRAYAGG(metadata_json) FROM pa_tenant_audit_event',
        )->fetchColumn();
        self::assertStringContainsString($reserved->reservationKey, $audit);
        self::assertStringNotContainsString('grant.standard', $audit);
        self::assertStringNotContainsString('effective_from', $audit);
        self::assertStringNotContainsString('canonical_snapshot_json', $audit);
    }

    public function testExceededCapacityIsAdvisoryForCheckAndAtomicForReserve(): void
    {
        [, $context] = $this->seedContext('req_entitlement_capacity');
        $clock = new MutableEntitlementClock('2030-02-15T12:00:00.000Z');
        $service = $this->service(new MutableEntitlementPolicyProvider($this->snapshot(limit: 10)), $clock);
        $authorized = $this->quotaContext($context, self::TARGET_KEY);
        $reserved = $service->reserve(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            7,
            'entitlement-reserve-capacity-01',
        );

        self::assertFalse(
            $service->check($authorized, self::METER, self::TARGET_TYPE, self::TARGET_KEY, 4)->allowed,
        );
        $this->assertQuotaError('ENTITLEMENT_QUOTA_EXCEEDED', fn() => $service->reserve(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            4,
            'entitlement-reserve-capacity-02',
        ));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_entitlement_reservation')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_idempotency_record')->fetchColumn());

        $released = $service->release(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            $reserved->reservationKey,
            'entitlement-release-capacity-01',
        );
        self::assertSame('released', $released->state);
        self::assertSame(10, $released->remainingAmount);
    }

    public function testExpiredSettlementPersistsAndReplaysAConflictWithoutProvider(): void
    {
        [, $context] = $this->seedContext('req_entitlement_expiry');
        $clock = new MutableEntitlementClock('2030-02-15T12:00:00.000Z');
        $provider = new MutableEntitlementPolicyProvider($this->snapshot(limit: 5, ttl: 30));
        $service = $this->service($provider, $clock);
        $authorized = $this->quotaContext($context, self::TARGET_KEY);
        $reserved = $service->reserve(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            5,
            'entitlement-reserve-expiry-001',
        );
        $provider->unavailable = true;
        $clock->set('2030-02-15T12:00:31.000Z');

        $this->assertQuotaError('ENTITLEMENT_QUOTA_CONFLICT', fn() => $service->commit(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            $reserved->reservationKey,
            'entitlement-commit-expiry-001',
        ));
        $this->assertQuotaError('ENTITLEMENT_QUOTA_CONFLICT', fn() => $service->commit(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            $reserved->reservationKey,
            'entitlement-commit-expiry-001',
        ));
        self::assertSame(
            'expired',
            (string) $this->pdo->query('SELECT state FROM pa_entitlement_reservation')->fetchColumn(),
        );
        self::assertSame(
            'failed',
            (string) $this->pdo->query(
                "SELECT status FROM pa_tenant_idempotency_record WHERE operation_key = 'entitlement-quota.commit'",
            )->fetchColumn(),
        );
        self::assertSame(0, (int) $this->pdo->query('SELECT committed_amount FROM pa_entitlement_usage_window')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM pa_tenant_audit_event WHERE event_type LIKE '%committed'",
        )->fetchColumn());
    }

    public function testRegistryProviderInputAndTargetFailuresAreFailClosed(): void
    {
        [, $context] = $this->seedContext('req_entitlement_fail_closed');
        $clock = new MutableEntitlementClock('2030-02-15T12:00:00.000Z');
        $authorized = $this->quotaContext($context, self::TARGET_KEY);

        $missingRegistry = new EntitlementQuotaService(
            new PdoEntitlementQuotaRepository($this->pdo),
            new FixedEntitlementMeterRegistry(null),
            new MutableEntitlementPolicyProvider($this->snapshot()),
            $clock,
        );
        $this->assertQuotaError('ENTITLEMENT_QUOTA_DENIED', fn() => $missingRegistry->usage(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
        ));

        $missingPolicy = $this->service(new MutableEntitlementPolicyProvider(null), $clock);
        $this->assertQuotaError('ENTITLEMENT_QUOTA_DENIED', fn() => $missingPolicy->usage(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
        ));

        $unavailable = new MutableEntitlementPolicyProvider($this->snapshot());
        $unavailable->unavailable = true;
        $this->assertQuotaError('ENTITLEMENT_QUOTA_PROVIDER_UNAVAILABLE', fn() => $this->service(
            $unavailable,
            $clock,
        )->usage($authorized, self::METER, self::TARGET_TYPE, self::TARGET_KEY));

        $malformed = new MutableEntitlementPolicyProvider(new EntitlementGrantSnapshot(
            'grant.standard',
            'policy.2030-02',
            self::METER,
            'other-unit',
            10,
            'utc_month',
            new DateTimeImmutable('2030-01-01T00:00:00.000Z'),
            new DateTimeImmutable('2030-06-01T00:00:00.000Z'),
            300,
        ));
        $this->assertQuotaError('ENTITLEMENT_QUOTA_INTEGRITY_FAILURE', fn() => $this->service(
            $malformed,
            $clock,
        )->usage($authorized, self::METER, self::TARGET_TYPE, self::TARGET_KEY));

        $service = $this->service(new MutableEntitlementPolicyProvider($this->snapshot()), $clock);
        $this->assertQuotaError('ENTITLEMENT_QUOTA_NOT_FOUND', fn() => $service->usage(
            $this->quotaContext($context, 'item-2'),
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
        ));
        $this->assertQuotaError('ENTITLEMENT_QUOTA_INVALID', fn() => $service->check(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            0,
        ));
    }

    public function testPolicyRevisionBytesAreImmutableAcrossProviderChanges(): void
    {
        [, $context] = $this->seedContext('req_entitlement_integrity');
        $clock = new MutableEntitlementClock('2030-02-15T12:00:00.000Z');
        $provider = new MutableEntitlementPolicyProvider($this->snapshot(limit: 10));
        $service = $this->service($provider, $clock);
        $authorized = $this->quotaContext($context, self::TARGET_KEY);
        $service->reserve(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            1,
            'entitlement-reserve-integrity',
        );

        $provider->snapshot = $this->snapshot(limit: 11);
        $this->assertQuotaError('ENTITLEMENT_QUOTA_INTEGRITY_FAILURE', fn() => $service->usage(
            $authorized,
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
        ));
    }

    public function testAuditFailureRollsBackPolicyReservationLedgerAndIdempotency(): void
    {
        [, $context] = $this->seedContext('req_entitlement_rollback');
        $service = $this->service(
            new MutableEntitlementPolicyProvider($this->snapshot()),
            new MutableEntitlementClock('2030-02-15T12:00:00.000Z'),
        );
        $this->pdo->exec(<<<'SQL'
CREATE TRIGGER entitlement_fail_audit BEFORE INSERT ON pa_tenant_audit_event
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entitlement audit failure'
SQL);

        try {
            $this->assertQuotaError('ENTITLEMENT_QUOTA_INTERNAL_ERROR', fn() => $service->reserve(
                $this->quotaContext($context, self::TARGET_KEY),
                self::METER,
                self::TARGET_TYPE,
                self::TARGET_KEY,
                1,
                'entitlement-reserve-rollback',
            ));
        } finally {
            $this->pdo->exec('DROP TRIGGER IF EXISTS entitlement_fail_audit');
        }

        foreach ([
            'pa_entitlement_grant',
            'pa_entitlement_policy_revision',
            'pa_entitlement_usage_window',
            'pa_entitlement_reservation',
            'pa_entitlement_usage_ledger',
            'pa_tenant_idempotency_record',
        ] as $table) {
            self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), $table);
        }
    }

    public function testTenantAndTargetIsolationDoNotEnumerateReservations(): void
    {
        [, $context] = $this->seedContext('req_entitlement_owner');
        [, $otherContext] = $this->seedContext('req_entitlement_other', 21, 201);
        $clock = new MutableEntitlementClock('2030-02-15T12:00:00.000Z');
        $service = $this->service(new MutableEntitlementPolicyProvider($this->snapshot()), $clock);
        $reserved = $service->reserve(
            $this->quotaContext($context, self::TARGET_KEY),
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            1,
            'entitlement-reserve-isolation',
        );

        $this->assertQuotaError('ENTITLEMENT_QUOTA_NOT_FOUND', fn() => $service->commit(
            $this->quotaContext($otherContext, self::TARGET_KEY),
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            $reserved->reservationKey,
            'entitlement-commit-isolation',
        ));
        $this->assertQuotaError('ENTITLEMENT_QUOTA_NOT_FOUND', fn() => $service->release(
            $this->quotaContext($context, 'item-2'),
            self::METER,
            self::TARGET_TYPE,
            self::TARGET_KEY,
            $reserved->reservationKey,
            'entitlement-release-isolation',
        ));
    }

    private function service(
        MutableEntitlementPolicyProvider $provider,
        MutableEntitlementClock $clock,
    ): EntitlementQuotaService {
        return new EntitlementQuotaService(
            new PdoEntitlementQuotaRepository($this->pdo),
            new FixedEntitlementMeterRegistry(new EntitlementMeter(self::METER, self::TARGET_TYPE, 'record')),
            $provider,
            $clock,
        );
    }

    private function snapshot(int $limit = 10, int $ttl = 300): EntitlementGrantSnapshot
    {
        return new EntitlementGrantSnapshot(
            'grant.standard',
            'policy.2030-02',
            self::METER,
            'record',
            $limit,
            'utc_month',
            new DateTimeImmutable('2030-01-01T00:00:00.000Z'),
            new DateTimeImmutable('2030-06-01T00:00:00.000Z'),
            $ttl,
        );
    }

    private function quotaContext(TenantContext $context, string $targetKey): AuthorizedOperationContext
    {
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $context,
            'host.resource',
            'consume',
            [new RequestedTargetSet(self::TARGET_TYPE, [$targetKey])],
            hash('sha256', self::METER . ':' . $targetKey),
        ));
    }

    /** @return array{int, TenantContext} */
    private function seedContext(string $requestId, int $memberId = 11, int $accountId = 101): array
    {
        $this->pdo->exec('INSERT INTO pa_tenant VALUES ()');
        $tenantId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO pa_tenant_member (id, tenant_id, account_id, status) VALUES (?, ?, ?, 'active')")
            ->execute([$memberId, $tenantId, $accountId]);

        return [$tenantId, TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $accountId,
            '01J00000000000000000000000',
            $tenantId,
            $accountId,
            $memberId,
            'admin-web',
            new DateTimeImmutable('2030-01-01T00:00:00.000Z'),
            1,
        ), $requestId)];
    }

    private function createKernelFixtures(): void
    {
        $this->pdo->exec('CREATE TABLE pa_tenant (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant_member (
  id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_entitlement_service_member (tenant_id, id)
) ENGINE=InnoDB
SQL);
        $this->pdo->exec(IdempotencySchema::tenant());
        $this->pdo->exec(KernelSchema::createSql('pa_tenant_audit_event'));
    }

    private function assertQuotaError(string $errorCode, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected {$errorCode}.");
        } catch (EntitlementQuotaException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
    }
}

final readonly class FixedEntitlementMeterRegistry implements EntitlementMeterRegistry
{
    public function __construct(private ?EntitlementMeter $meter) {}

    public function find(string $meterKey, string $targetType): ?EntitlementMeter
    {
        if ($this->meter === null
            || $this->meter->meterKey !== $meterKey
            || $this->meter->targetType !== $targetType) {
            return null;
        }

        return $this->meter;
    }
}

final class MutableEntitlementPolicyProvider implements EntitlementPolicyProvider
{
    public bool $unavailable = false;

    public function __construct(public ?EntitlementGrantSnapshot $snapshot) {}

    public function snapshot(
        AuthorizedOperationContext $context,
        EntitlementMeter $meter,
        string $targetKey,
        DateTimeImmutable $evaluatedAt,
    ): ?EntitlementGrantSnapshot {
        if ($this->unavailable) {
            throw new RuntimeException('Provider unavailable.');
        }

        return $this->snapshot;
    }
}

final class MutableEntitlementClock implements Clock
{
    private DateTimeImmutable $current;

    public function __construct(string $current)
    {
        $this->set($current);
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function set(string $current): void
    {
        $this->current = new DateTimeImmutable($current, new DateTimeZone('UTC'));
    }
}
