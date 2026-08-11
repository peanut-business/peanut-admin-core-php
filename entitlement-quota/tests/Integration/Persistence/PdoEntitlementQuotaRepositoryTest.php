<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Tests\Integration\Persistence;

use PDO;
use PeanutAdmin\EntitlementQuota\Database\Schema;
use PeanutAdmin\EntitlementQuota\Model\EntitlementPolicyRevision;
use PeanutAdmin\EntitlementQuota\Model\EntitlementUsageWindow;
use PeanutAdmin\EntitlementQuota\Persistence\PdoEntitlementQuotaRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class PdoEntitlementQuotaRepositoryTest extends TestCase
{
    private const DATABASE = 'peanut_admin_p1_entitlement_repository_test';

    private PDO $admin;
    private PDO $pdo;
    private PdoEntitlementQuotaRepository $repository;

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
        $this->repository = new PdoEntitlementQuotaRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        }
    }

    public function testSchemaCanReenterWithoutChangingKernelOrEntitlementRows(): void
    {
        [$tenantId, $memberId] = $this->seedTenant(11, 101);
        $this->transaction(fn() => $this->policy($tenantId, $memberId));

        foreach (Schema::createSql() as $statement) {
            $this->pdo->exec($statement);
        }

        self::assertSame([
            'pa_entitlement_grant',
            'pa_entitlement_policy_revision',
            'pa_entitlement_reservation',
            'pa_entitlement_usage_ledger',
            'pa_entitlement_usage_window',
        ], $this->entitlementTables());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_entitlement_grant')->fetchColumn());
    }

    public function testPersistsImmutableTenantScopedPolicySnapshotAndCurrentPointer(): void
    {
        [$tenantId, $memberId] = $this->seedTenant(11, 101);
        [$otherTenantId] = $this->seedTenant(21, 201);

        $policy = $this->transaction(fn() => $this->policy($tenantId, $memberId));
        self::assertInstanceOf(EntitlementPolicyRevision::class, $policy);
        self::assertSame($policy->id, $this->repository->grant($tenantId, 'grant.standard')?->currentPolicyRevisionId);
        self::assertNull($this->repository->policyRevision($otherTenantId, 'policy.v1'));
        self::assertSame($policy->id, $this->repository->policyRevisionById($tenantId, $policy->id)?->id);
        self::assertNull($this->repository->policyRevisionById($otherTenantId, $policy->id));

        $same = $this->transaction(fn() => $this->policy($tenantId, $memberId));
        self::assertSame($policy->id, $same->id);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_entitlement_policy_revision')->fetchColumn());

        $this->expectException(UnexpectedValueException::class);
        $this->transaction(fn() => $this->repository->lockOrCreatePolicyRevision(
            $tenantId,
            'grant.standard',
            'policy.v1',
            'requests.total',
            'request',
            11,
            'utc_month',
            '2026-08-01 00:00:00.000',
            '2026-09-01 00:00:00.000',
            300,
            $this->snapshot(11),
            hash('sha256', $this->snapshot(11)),
            $memberId,
            $this->now(),
        ));
    }

    public function testAtomicallyReservesCommitsReleasesAndExpiresCapacity(): void
    {
        [$tenantId, $memberId] = $this->seedTenant(11, 101);
        $window = $this->transaction(function () use ($tenantId, $memberId): EntitlementUsageWindow {
            $policy = $this->policy($tenantId, $memberId);

            return $this->repository->lockOrCreateUsageWindow(
                $tenantId,
                $policy->id,
                $policy->meterKey,
                'workspace',
                'workspace-1',
                '2026-08-01 00:00:00.000',
                '2026-09-01 00:00:00.000',
                $this->now(),
            );
        });

        $first = $this->transaction(fn() => $this->repository->createReservation(
            $tenantId,
            $window->id,
            'reservation_' . str_repeat('a', 32),
            6,
            10,
            $memberId,
            '2026-08-12 00:00:00.000',
            '2026-08-12 00:05:00.000',
        ));
        self::assertSame('pending', $first->state);
        self::assertSame(6, $this->repository->livePendingAmount($tenantId, $window->id, $this->now()));

        try {
            $this->transaction(fn() => $this->repository->createReservation(
                $tenantId,
                $window->id,
                'reservation_' . str_repeat('b', 32),
                5,
                10,
                $memberId,
                '2026-08-12 00:00:01.000',
                '2026-08-12 00:05:01.000',
            ));
            self::fail('A reservation above committed plus pending capacity must fail.');
        } catch (RuntimeException) {
            self::assertNull($this->repository->reservation($tenantId, 'reservation_' . str_repeat('b', 32)));
        }

        $committed = $this->transaction(fn() => $this->repository->settleReservation(
            $tenantId,
            $first->reservationKey,
            'committed',
            $memberId,
            '2026-08-12 00:01:00.000',
        ));
        self::assertSame('committed', $committed->state);
        self::assertSame(6, $this->repository->usageWindowById($tenantId, $window->id)?->committedAmount);

        $released = $this->transaction(function () use ($tenantId, $window, $memberId) {
            $reservation = $this->repository->createReservation(
                $tenantId,
                $window->id,
                'reservation_' . str_repeat('c', 32),
                4,
                10,
                $memberId,
                '2026-08-12 00:02:00.000',
                '2026-08-12 00:07:00.000',
            );

            return $this->repository->settleReservation(
                $tenantId,
                $reservation->reservationKey,
                'released',
                $memberId,
                '2026-08-12 00:03:00.000',
            );
        });
        self::assertSame('released', $released->state);

        $expiredKey = 'reservation_' . str_repeat('d', 32);
        $this->transaction(fn() => $this->repository->createReservation(
            $tenantId,
            $window->id,
            $expiredKey,
            4,
            10,
            $memberId,
            '2026-08-12 00:04:00.000',
            '2026-08-12 00:05:00.000',
        ));
        $this->transaction(fn() => $this->repository->createReservation(
            $tenantId,
            $window->id,
            'reservation_' . str_repeat('e', 32),
            4,
            10,
            $memberId,
            '2026-08-12 00:06:00.000',
            '2026-08-12 00:11:00.000',
        ));
        self::assertSame('expired', $this->repository->reservation($tenantId, $expiredKey)?->state);
        self::assertSame(7, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_entitlement_usage_ledger')->fetchColumn());
    }

    public function testSettlementAndReadsFailClosedAcrossTenants(): void
    {
        [$tenantId, $memberId] = $this->seedTenant(11, 101);
        [$otherTenantId, $otherMemberId] = $this->seedTenant(21, 201);
        $window = $this->transaction(function () use ($tenantId, $memberId): EntitlementUsageWindow {
            $policy = $this->policy($tenantId, $memberId);

            return $this->repository->lockOrCreateUsageWindow(
                $tenantId,
                $policy->id,
                $policy->meterKey,
                'workspace',
                'workspace-1',
                '2026-08-01 00:00:00.000',
                '2026-09-01 00:00:00.000',
                $this->now(),
            );
        });
        $reservation = $this->transaction(fn() => $this->repository->createReservation(
            $tenantId,
            $window->id,
            'reservation_' . str_repeat('f', 32),
            1,
            10,
            $memberId,
            $this->now(),
            '2026-08-12 00:05:00.000',
        ));

        self::assertNull($this->repository->usageWindowById($otherTenantId, $window->id));
        self::assertNull($this->repository->reservation($otherTenantId, $reservation->reservationKey));
        try {
            $this->transaction(fn() => $this->repository->settleReservation(
                $otherTenantId,
                $reservation->reservationKey,
                'committed',
                $otherMemberId,
                '2026-08-12 00:01:00.000',
            ));
            self::fail('A cross-Tenant settlement must fail closed.');
        } catch (RuntimeException) {
            self::assertSame('pending', $this->repository->reservation($tenantId, $reservation->reservationKey)?->state);
        }
    }

    public function testCapacityWritesRequireOneCallerOwnedTransaction(): void
    {
        [$tenantId, $memberId] = $this->seedTenant(11, 101);

        $this->expectException(RuntimeException::class);
        $this->repository->lockOrCreatePolicyRevision(
            $tenantId,
            'grant.standard',
            'policy.v1',
            'requests.total',
            'request',
            10,
            'utc_month',
            '2026-08-01 00:00:00.000',
            '2026-09-01 00:00:00.000',
            300,
            $this->snapshot(10),
            hash('sha256', $this->snapshot(10)),
            $memberId,
            $this->now(),
        );
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
  UNIQUE KEY uk_entitlement_test_member (tenant_id, id)
) ENGINE=InnoDB
SQL);
    }

    /** @return array{int, int} */
    private function seedTenant(int $memberId, int $accountId): array
    {
        $this->pdo->exec('INSERT INTO pa_tenant VALUES ()');
        $tenantId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO pa_tenant_member (id, tenant_id, account_id, status) VALUES (?, ?, ?, 'active')")
            ->execute([$memberId, $tenantId, $accountId]);

        return [$tenantId, $memberId];
    }

    private function policy(int $tenantId, int $memberId): EntitlementPolicyRevision
    {
        $snapshot = $this->snapshot(10);

        return $this->repository->lockOrCreatePolicyRevision(
            $tenantId,
            'grant.standard',
            'policy.v1',
            'requests.total',
            'request',
            10,
            'utc_month',
            '2026-08-01 00:00:00.000',
            '2026-09-01 00:00:00.000',
            300,
            $snapshot,
            hash('sha256', $snapshot),
            $memberId,
            $this->now(),
        );
    }

    private function snapshot(int $limit): string
    {
        return '{"effective_from":"2026-08-01T00:00:00.000Z","effective_until":"2026-09-01T00:00:00.000Z","grant_key":"grant.standard","limit_amount":'
            . $limit
            . ',"meter_key":"requests.total","period_kind":"utc_month","policy_revision_key":"policy.v1","reservation_ttl_seconds":300,"unit_key":"request"}';
    }

    /** @return list<string> */
    private function entitlementTables(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_entitlement%'
ORDER BY table_name
SQL);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function now(): string
    {
        return '2026-08-12 00:00:00.000';
    }
}
