<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Tests\Integration\Application;

use DateTimeImmutable;
use PDO;
use PeanutAdmin\ArtifactRevision\Application\ArtifactRevisionException;
use PeanutAdmin\ArtifactRevision\Application\ArtifactRevisionReceipt;
use PeanutAdmin\ArtifactRevision\Application\ArtifactRevisionService;
use PeanutAdmin\ArtifactRevision\Database\Schema;
use PeanutAdmin\ArtifactRevision\Persistence\PdoArtifactRevisionRepository;
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

final class ArtifactRevisionServiceTest extends TestCase
{
    private const DATABASE = 'peanut_admin_p1_artifact_service_test';

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

    public function testPublicSurfaceAndReceiptReplayShapeAreStable(): void
    {
        self::assertSame(
            ['createRevision', 'finalizeRevision', 'revision', 'compare'],
            array_values(array_map(
                static fn(ReflectionMethod $method): string => $method->getName(),
                array_filter(
                    (new ReflectionClass(ArtifactRevisionService::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                    static fn(ReflectionMethod $method): bool => $method->getDeclaringClass()->getName()
                        === ArtifactRevisionService::class && $method->getName() !== '__construct',
                ),
            )),
        );
        $receipt = new ArtifactRevisionReceipt(
            'artifact-revision.create',
            'document.article',
            'article-1',
            2,
            'revision_' . str_repeat('a', 32),
            1,
            null,
            'pending',
            1,
            null,
        );
        self::assertSame(
            $receipt->toArray(),
            ArtifactRevisionReceipt::fromArray($receipt->toArray(), 'artifact-revision.create')->toArray(),
        );
    }

    public function testCreateFinalizeReplayReadAndCompareLineage(): void
    {
        [$tenantId, $context] = $this->seedContext('req_artifact_service');
        $service = new ArtifactRevisionService(new PdoArtifactRevisionRepository($this->pdo));

        $firstCreate = $service->createRevision(
            $this->artifactContext($context, 'document.article', 'article-1', 'write'),
            'document.article',
            'article-1',
            null,
            null,
            'artifact-create-first-0001',
        );
        $firstReplay = $service->createRevision(
            $this->artifactContext($context, 'document.article', 'article-1', 'write'),
            'document.article',
            'article-1',
            null,
            null,
            'artifact-create-first-0001',
        );
        self::assertSame($firstCreate->toArray(), $firstReplay->toArray());

        $firstFinalize = $service->finalizeRevision(
            $this->artifactContext($context, 'document.article', 'article-1', 'write'),
            'document.article',
            'article-1',
            $firstCreate->revisionKey,
            $firstCreate->artifactRevision,
            $firstCreate->revision,
            'article.body',
            '1',
            'payload/article-1/r1',
            str_repeat('a', 64),
            null,
            'artifact-finalize-first-01',
        );
        $firstFinalizeReplay = $service->finalizeRevision(
            $this->artifactContext($context, 'document.article', 'article-1', 'write'),
            'document.article',
            'article-1',
            $firstCreate->revisionKey,
            $firstCreate->artifactRevision,
            $firstCreate->revision,
            'article.body',
            '1',
            'payload/article-1/r1',
            str_repeat('a', 64),
            null,
            'artifact-finalize-first-01',
        );
        self::assertSame($firstFinalize->toArray(), $firstFinalizeReplay->toArray());

        $second = $this->createAndFinalize(
            $service,
            $context,
            'article-1',
            $firstFinalize->revisionKey,
            $firstFinalize->artifactRevision,
            'second',
        );
        $third = $this->createAndFinalize(
            $service,
            $context,
            'article-1',
            $firstFinalize->revisionKey,
            $second->artifactRevision,
            'third',
        );

        self::assertSame('finalized', $service->revision(
            $this->artifactContext($context, 'document.article', 'article-1', 'read'),
            'document.article',
            'article-1',
            $second->revisionKey,
        )->state);
        self::assertSame('ancestor', $service->compare(
            $this->artifactContext($context, 'document.article', 'article-1', 'read'),
            'document.article',
            'article-1',
            $firstFinalize->revisionKey,
            $second->revisionKey,
        )->relationship);
        self::assertSame('descendant', $service->compare(
            $this->artifactContext($context, 'document.article', 'article-1', 'read'),
            'document.article',
            'article-1',
            $second->revisionKey,
            $firstFinalize->revisionKey,
        )->relationship);
        self::assertSame('diverged', $service->compare(
            $this->artifactContext($context, 'document.article', 'article-1', 'read'),
            'document.article',
            'article-1',
            $second->revisionKey,
            $third->revisionKey,
        )->relationship);
        self::assertSame('same', $service->compare(
            $this->artifactContext($context, 'document.article', 'article-1', 'read'),
            'document.article',
            'article-1',
            $third->revisionKey,
            $third->revisionKey,
        )->relationship);

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_artifact')->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_artifact_revision')->fetchColumn());
        self::assertSame(6, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
        self::assertSame(6, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_idempotency_record')->fetchColumn());
        self::assertSame($tenantId, (int) $this->pdo->query('SELECT tenant_id FROM pa_artifact')->fetchColumn());

        $auditJson = (string) $this->pdo->query(
            'SELECT JSON_ARRAYAGG(metadata_json) FROM pa_tenant_audit_event',
        )->fetchColumn();
        self::assertStringNotContainsString('payload/article-1', $auditJson);
        self::assertStringNotContainsString('canonical_envelope_json', $auditJson);
    }

    public function testTenantTargetAndOptimisticFailuresDoNotEnumerateProtectedRows(): void
    {
        [, $context] = $this->seedContext('req_artifact_owner');
        [, $otherContext] = $this->seedContext('req_artifact_other', 21, 201);
        $service = new ArtifactRevisionService(new PdoArtifactRevisionRepository($this->pdo));
        $created = $service->createRevision(
            $this->artifactContext($context, 'document.article', 'article-1', 'write'),
            'document.article',
            'article-1',
            null,
            null,
            'artifact-isolation-create',
        );

        $this->assertArtifactError('ARTIFACT_REVISION_NOT_FOUND', fn() => $service->revision(
            $this->artifactContext($otherContext, 'document.article', 'article-1', 'read'),
            'document.article',
            'article-1',
            $created->revisionKey,
        ));
        $this->assertArtifactError('ARTIFACT_REVISION_NOT_FOUND', fn() => $service->revision(
            $this->artifactContext($context, 'document.article', 'article-2', 'read'),
            'document.article',
            'article-1',
            $created->revisionKey,
        ));
        $this->assertArtifactError('ARTIFACT_REVISION_CONFLICT', fn() => $service->createRevision(
            $this->artifactContext($context, 'document.article', 'article-1', 'write'),
            'document.article',
            'article-1',
            null,
            1,
            'artifact-stale-create-001',
        ));
        $this->assertArtifactError('ARTIFACT_REVISION_INVALID', fn() => $service->createRevision(
            $this->artifactContext($context, 'Document Article', 'article-1', 'write'),
            'Document Article',
            'article-1',
            null,
            null,
            'artifact-invalid-create',
        ));
    }

    public function testAuditFailureRollsBackArtifactRevisionAndIdempotency(): void
    {
        [, $context] = $this->seedContext('req_artifact_rollback');
        $service = new ArtifactRevisionService(new PdoArtifactRevisionRepository($this->pdo));
        $this->pdo->exec(<<<'SQL'
CREATE TRIGGER artifact_revision_fail_audit BEFORE INSERT ON pa_tenant_audit_event
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'artifact audit failure'
SQL);

        try {
            $this->assertArtifactError('ARTIFACT_REVISION_INTERNAL_ERROR', fn() => $service->createRevision(
                $this->artifactContext($context, 'document.article', 'article-rollback', 'write'),
                'document.article',
                'article-rollback',
                null,
                null,
                'artifact-rollback-create',
            ));
        } finally {
            $this->pdo->exec('DROP TRIGGER IF EXISTS artifact_revision_fail_audit');
        }

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_artifact')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_artifact_revision')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_idempotency_record')->fetchColumn());
    }

    private function createAndFinalize(
        ArtifactRevisionService $service,
        TenantContext $context,
        string $artifactKey,
        string $parentRevisionKey,
        int $expectedArtifactRevision,
        string $suffix,
    ): ArtifactRevisionReceipt {
        $created = $service->createRevision(
            $this->artifactContext($context, 'document.article', $artifactKey, 'write'),
            'document.article',
            $artifactKey,
            $parentRevisionKey,
            $expectedArtifactRevision,
            "artifact-create-{$suffix}-0001",
        );

        return $service->finalizeRevision(
            $this->artifactContext($context, 'document.article', $artifactKey, 'write'),
            'document.article',
            $artifactKey,
            $created->revisionKey,
            $created->artifactRevision,
            $created->revision,
            'article.body',
            '1',
            "payload/{$artifactKey}/{$suffix}",
            hash('sha256', $suffix),
            null,
            "artifact-finalize-{$suffix}-01",
        );
    }

    private function artifactContext(
        TenantContext $context,
        string $artifactType,
        string $artifactKey,
        string $operation,
    ): AuthorizedOperationContext {
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $context,
            $artifactType,
            $operation,
            [new RequestedTargetSet($artifactType, [$artifactKey])],
            hash('sha256', "{$artifactType}:{$artifactKey}:{$operation}"),
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
  UNIQUE KEY uk_artifact_service_member (tenant_id, id)
) ENGINE=InnoDB
SQL);
        $this->pdo->exec(IdempotencySchema::tenant());
        $this->pdo->exec(KernelSchema::createSql('pa_tenant_audit_event'));
    }

    private function assertArtifactError(string $errorCode, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected {$errorCode}.");
        } catch (ArtifactRevisionException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
    }
}
