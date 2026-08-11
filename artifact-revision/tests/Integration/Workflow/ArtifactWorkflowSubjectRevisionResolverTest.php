<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Tests\Integration\Workflow;

use DateTimeImmutable;
use LogicException;
use PDO;
use PeanutAdmin\ArtifactRevision\Model\Artifact;
use PeanutAdmin\ArtifactRevision\Model\ArtifactRevision;
use PeanutAdmin\ArtifactRevision\Persistence\ArtifactRevisionRepository;
use PeanutAdmin\ArtifactRevision\Workflow\ArtifactWorkflowSubjectRevisionResolver;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Workflow\Application\WorkflowException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ArtifactWorkflowSubjectRevisionResolverTest extends TestCase
{
    public function testMapsFinalizedArtifactRevisionToOpaqueWorkflowPin(): void
    {
        $revision = $this->revision('finalized');
        $repository = new ArtifactRevisionResolverRepository($revision);
        $resolver = new ArtifactWorkflowSubjectRevisionResolver($repository);

        self::assertSame($repository->connection(), $resolver->connection());
        self::assertSame([
            'revision_key' => $revision->revisionKey,
            'sha256' => $revision->canonicalEnvelopeSha256,
        ], $resolver->resolve(
            $this->context(1, 'document.article', 'article-1'),
            'document.article',
            'article-1',
            $revision->revisionKey,
        ));
    }

    public function testPendingMissingMismatchedAndCrossTenantSubjectsFailClosed(): void
    {
        $pending = new ArtifactRevisionResolverRepository($this->revision('pending'));
        $this->assertWorkflowError('WORKFLOW_SUBJECT_REVISION_CONFLICT', fn() => (
            new ArtifactWorkflowSubjectRevisionResolver($pending)
        )->resolve(
            $this->context(1, 'document.article', 'article-1'),
            'document.article',
            'article-1',
            'revision_' . str_repeat('a', 32),
        ));

        $missing = new ArtifactRevisionResolverRepository(null);
        $this->assertWorkflowError('WORKFLOW_SUBJECT_REVISION_CONFLICT', fn() => (
            new ArtifactWorkflowSubjectRevisionResolver($missing)
        )->resolve(
            $this->context(2, 'document.article', 'article-1'),
            'document.article',
            'article-1',
            'revision_' . str_repeat('a', 32),
        ));

        $finalized = new ArtifactRevisionResolverRepository($this->revision('finalized'));
        $this->assertWorkflowError('WORKFLOW_SUBJECT_NOT_FOUND', fn() => (
            new ArtifactWorkflowSubjectRevisionResolver($finalized)
        )->resolve(
            $this->context(1, 'document.article', 'article-2'),
            'document.article',
            'article-1',
            'revision_' . str_repeat('a', 32),
        ));
        $this->assertWorkflowError('WORKFLOW_SUBJECT_REVISION_CONFLICT', fn() => (
            new ArtifactWorkflowSubjectRevisionResolver($finalized)
        )->resolve(
            $this->context(1, 'document.article', 'article-1'),
            'document.article',
            'article-1',
            'not-a-revision',
        ));
    }

    public function testPersistenceIntegrityFailureMapsToWorkflowInternalError(): void
    {
        $repository = new ArtifactRevisionResolverRepository(null, true);
        $this->assertWorkflowError('INTERNAL_ERROR', fn() => (
            new ArtifactWorkflowSubjectRevisionResolver($repository)
        )->resolve(
            $this->context(1, 'document.article', 'article-1'),
            'document.article',
            'article-1',
            'revision_' . str_repeat('a', 32),
        ));
    }

    private function revision(string $state): ArtifactRevision
    {
        $revisionKey = 'revision_' . str_repeat('a', 32);
        $envelope = [
            'artifact_type' => 'document.article',
            'artifact_key' => 'article-1',
            'revision_key' => $revisionKey,
            'revision_number' => 1,
            'parent_revision_key' => null,
            'payload_schema_key' => 'article.body',
            'payload_schema_version' => '1',
            'payload_ref' => 'payload/article-1/r1',
            'payload_sha256' => str_repeat('b', 64),
            'attachment_manifest_sha256' => null,
        ];
        $json = ArtifactRevision::encodeEnvelope($envelope);
        $finalized = $state === 'finalized';

        return new ArtifactRevision(
            1,
            1,
            1,
            'document.article',
            'article-1',
            $revisionKey,
            1,
            null,
            null,
            $state,
            $finalized ? 2 : 1,
            $finalized ? 'article.body' : null,
            $finalized ? '1' : null,
            $finalized ? 'payload/article-1/r1' : null,
            $finalized ? str_repeat('b', 64) : null,
            null,
            $finalized ? $json : null,
            $finalized ? hash('sha256', $json) : null,
            11,
            $finalized ? 11 : null,
            '2026-08-12 00:00:00.000',
            $finalized ? '2026-08-12 00:00:01.000' : null,
        );
    }

    private function context(int $tenantId, string $artifactType, string $artifactKey): AuthorizedOperationContext
    {
        $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            101,
            '01J00000000000000000000000',
            $tenantId,
            101,
            11,
            'admin-web',
            new DateTimeImmutable('2030-01-01T00:00:00.000Z'),
            1,
        ), 'req_artifact_resolver');

        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            $artifactType,
            'read',
            [new RequestedTargetSet($artifactType, [$artifactKey])],
            hash('sha256', "{$artifactType}:{$artifactKey}:read"),
        ));
    }

    private function assertWorkflowError(string $errorCode, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected {$errorCode}.");
        } catch (WorkflowException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
    }
}

final class ArtifactRevisionResolverRepository implements ArtifactRevisionRepository
{
    private PDO $pdo;

    public function __construct(
        private readonly ?ArtifactRevision $resolved,
        private readonly bool $failIntegrity = false,
    ) {
        $this->pdo = new PDO('sqlite::memory:');
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function artifact(int $tenantId, string $artifactType, string $artifactKey, bool $forUpdate = false): ?Artifact
    {
        throw new LogicException('Unused resolver test method.');
    }

    public function lockOrCreateArtifact(
        int $tenantId,
        string $artifactType,
        string $artifactKey,
        int $memberId,
        ?int $expectedRevision,
        string $now,
    ): Artifact {
        throw new LogicException('Unused resolver test method.');
    }

    public function createPendingRevision(
        int $tenantId,
        int $artifactId,
        string $revisionKey,
        ?int $parentRevisionId,
        int $expectedArtifactRevision,
        int $memberId,
        string $now,
    ): ArtifactRevision {
        throw new LogicException('Unused resolver test method.');
    }

    public function revision(
        int $tenantId,
        string $artifactType,
        string $artifactKey,
        string $revisionKey,
        bool $forUpdate = false,
    ): ?ArtifactRevision {
        if ($this->failIntegrity) {
            throw new UnexpectedValueException('tampered');
        }

        return $this->resolved;
    }

    public function revisionById(
        int $tenantId,
        int $artifactId,
        int $revisionId,
        bool $forUpdate = false,
    ): ?ArtifactRevision {
        throw new LogicException('Unused resolver test method.');
    }

    public function finalizeRevision(
        int $tenantId,
        int $artifactId,
        string $revisionKey,
        int $expectedArtifactRevision,
        int $expectedRevision,
        int $memberId,
        string $payloadSchemaKey,
        string $payloadSchemaVersion,
        string $payloadRef,
        string $payloadSha256,
        ?string $attachmentManifestSha256,
        string $now,
    ): ArtifactRevision {
        throw new LogicException('Unused resolver test method.');
    }
}
