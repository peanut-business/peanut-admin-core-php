<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Tests\Integration\Persistence;

use PDO;
use PeanutAdmin\ArtifactRevision\Database\Schema;
use PeanutAdmin\ArtifactRevision\Persistence\PdoArtifactRevisionRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class PdoArtifactRevisionRepositoryTest extends TestCase
{
    private const DATABASE = 'peanut_admin_p1_artifact_repository_test';

    private PDO $admin;
    private PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-ARTIFACT-REVISION-001 MySQL qualification.');
        }
        $port = (int) (getenv('DB_PORT') ?: 0);
        if (getenv('DB_HOST') !== '127.0.0.1'
            || $port < 1024
            || $port > 65535
            || $port !== (int) getenv('MYSQL_PORT')) {
            throw new RuntimeException('ArtifactRevision qualification requires an explicit local MySQL port.');
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

    public function testSchemaCanReenterWithoutChangingKernelOrArtifactRows(): void
    {
        [$tenantId] = $this->seedTenant(11, 101);
        $repository = new PdoArtifactRevisionRepository($this->pdo);
        $repository->lockOrCreateArtifact($tenantId, 'document.article', 'article-1', 11, null, $this->now());

        foreach (Schema::createSql() as $statement) {
            $this->pdo->exec($statement);
        }

        self::assertSame(['pa_artifact', 'pa_artifact_revision'], $this->artifactTables());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_artifact')->fetchColumn());
    }

    public function testReservesFinalizesAndReadsTenantScopedParentLineage(): void
    {
        [$tenantId] = $this->seedTenant(11, 101);
        [$otherTenantId] = $this->seedTenant(21, 201);
        $repository = new PdoArtifactRevisionRepository($this->pdo);
        $artifact = $repository->lockOrCreateArtifact(
            $tenantId,
            'document.article',
            'article-1',
            11,
            null,
            $this->now(),
        );
        $first = $repository->createPendingRevision(
            $tenantId,
            $artifact->id,
            'revision_' . str_repeat('a', 32),
            null,
            1,
            11,
            $this->now(),
        );
        self::assertSame('pending', $first->state);
        self::assertSame(1, $first->revisionNumber);

        $first = $repository->finalizeRevision(
            $tenantId,
            $artifact->id,
            $first->revisionKey,
            2,
            1,
            11,
            'article.body',
            '1',
            'payload/article-1/r1',
            str_repeat('b', 64),
            null,
            $this->now(),
        );
        self::assertTrue($first->isFinalized());
        self::assertSame(hash('sha256', $first->canonicalEnvelopeJson ?? ''), $first->canonicalEnvelopeSha256);

        $artifact = $repository->lockOrCreateArtifact(
            $tenantId,
            'document.article',
            'article-1',
            11,
            3,
            $this->now(),
        );
        $second = $repository->createPendingRevision(
            $tenantId,
            $artifact->id,
            'revision_' . str_repeat('c', 32),
            $first->id,
            3,
            11,
            $this->now(),
        );
        $second = $repository->finalizeRevision(
            $tenantId,
            $artifact->id,
            $second->revisionKey,
            4,
            1,
            11,
            'article.body',
            '1',
            'payload/article-1/r2',
            str_repeat('d', 64),
            str_repeat('e', 64),
            $this->now(),
        );

        self::assertSame($first->revisionKey, $second->parentRevisionKey);
        self::assertSame(2, $second->revisionNumber);
        self::assertNull($repository->revision(
            $otherTenantId,
            'document.article',
            'article-1',
            $second->revisionKey,
        ));
        self::assertSame(
            $second->id,
            $repository->artifact($tenantId, 'document.article', 'article-1')?->latestFinalizedRevisionId,
        );
    }

    public function testOptimisticAndImmutableGuardsRejectStaleWrites(): void
    {
        [$tenantId] = $this->seedTenant(11, 101);
        $repository = new PdoArtifactRevisionRepository($this->pdo);
        $artifact = $repository->lockOrCreateArtifact(
            $tenantId,
            'document.article',
            'article-1',
            11,
            null,
            $this->now(),
        );
        $pending = $repository->createPendingRevision(
            $tenantId,
            $artifact->id,
            'revision_' . str_repeat('a', 32),
            null,
            1,
            11,
            $this->now(),
        );

        $this->assertRuntimeFailure(fn() => $repository->createPendingRevision(
            $tenantId,
            $artifact->id,
            'revision_' . str_repeat('b', 32),
            null,
            1,
            11,
            $this->now(),
        ));
        $repository->finalizeRevision(
            $tenantId,
            $artifact->id,
            $pending->revisionKey,
            2,
            1,
            11,
            'article.body',
            '1',
            'payload/article-1/r1',
            str_repeat('c', 64),
            null,
            $this->now(),
        );
        $this->assertRuntimeFailure(fn() => $repository->finalizeRevision(
            $tenantId,
            $artifact->id,
            $pending->revisionKey,
            3,
            2,
            11,
            'article.body',
            '1',
            'payload/article-1/r1',
            str_repeat('c', 64),
            null,
            $this->now(),
        ));
    }

    public function testFinalizedEnvelopeTamperingFailsClosed(): void
    {
        [$tenantId] = $this->seedTenant(11, 101);
        $repository = new PdoArtifactRevisionRepository($this->pdo);
        $artifact = $repository->lockOrCreateArtifact(
            $tenantId,
            'document.article',
            'article-1',
            11,
            null,
            $this->now(),
        );
        $pending = $repository->createPendingRevision(
            $tenantId,
            $artifact->id,
            'revision_' . str_repeat('a', 32),
            null,
            1,
            11,
            $this->now(),
        );
        $repository->finalizeRevision(
            $tenantId,
            $artifact->id,
            $pending->revisionKey,
            2,
            1,
            11,
            'article.body',
            '1',
            'payload/article-1/r1',
            str_repeat('b', 64),
            null,
            $this->now(),
        );
        $this->pdo->exec("UPDATE pa_artifact_revision SET canonical_envelope_sha256 = REPEAT('0', 64)");

        try {
            $repository->revision($tenantId, 'document.article', 'article-1', $pending->revisionKey);
            self::fail('A tampered finalized envelope must fail closed.');
        } catch (UnexpectedValueException $exception) {
            self::assertStringNotContainsString('payload/article-1/r1', $exception->getMessage());
        }
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
  UNIQUE KEY uk_artifact_test_member (tenant_id, id)
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

    /** @return list<string> */
    private function artifactTables(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_artifact%'
ORDER BY table_name
SQL);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function assertRuntimeFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('A stale or immutable repository write must fail.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }

    private function now(): string
    {
        return '2026-08-12 00:00:00.000';
    }
}
