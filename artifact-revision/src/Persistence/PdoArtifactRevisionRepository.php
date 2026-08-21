<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Persistence;

use PDO;
use PDOException;
use PDOStatement;
use PeanutAdmin\ArtifactRevision\Model\Artifact;
use PeanutAdmin\ArtifactRevision\Model\ArtifactRevision;
use RuntimeException;
use UnexpectedValueException;

final readonly class PdoArtifactRevisionRepository implements ArtifactRevisionRepository
{
    public function __construct(private PDO $pdo) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function artifact(
        int $tenantId,
        string $artifactType,
        string $artifactKey,
        bool $forUpdate = false,
    ): ?Artifact {
        $row = $this->fetchOne(<<<'SQL'
SELECT * FROM pa_artifact
WHERE tenant_id = :tenant_id AND artifact_type = :artifact_type AND artifact_key = :artifact_key
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'artifact_type' => $artifactType,
            'artifact_key' => $artifactKey,
        ]);

        return $row === null ? null : Artifact::fromRow($row);
    }

    public function lockOrCreateArtifact(
        int $tenantId,
        string $artifactType,
        string $artifactKey,
        int $memberId,
        ?int $expectedRevision,
        string $now,
    ): Artifact {
        $existing = $this->artifact($tenantId, $artifactType, $artifactKey, true);
        if ($existing !== null) {
            if ($expectedRevision === null || $existing->revision !== $expectedRevision) {
                throw $this->conflict('The artifact revision has changed.');
            }

            return $existing;
        }
        if ($expectedRevision !== null) {
            throw $this->conflict('The artifact identity is unavailable.');
        }

        try {
            $this->execute(<<<'SQL'
INSERT INTO pa_artifact (
  tenant_id, artifact_type, artifact_key, revision, next_revision_number,
  latest_finalized_revision_id, created_by_member_id, updated_by_member_id,
  created_at, updated_at
) VALUES (
  :tenant_id, :artifact_type, :artifact_key, 1, 1, NULL,
  :member_id, :member_id, :created_at, :updated_at
)
SQL, [
                'tenant_id' => $tenantId,
                'artifact_type' => $artifactType,
                'artifact_key' => $artifactKey,
                'member_id' => $memberId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicate($exception)) {
                throw $this->conflict('The artifact identity already exists.');
            }
            throw $exception;
        }

        return $this->artifact($tenantId, $artifactType, $artifactKey, true)
            ?? throw new RuntimeException('The inserted artifact could not be read back.');
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
        $artifact = $this->artifactById($tenantId, $artifactId, true)
            ?? throw $this->notFound('The artifact is unavailable.');
        if ($artifact->revision !== $expectedArtifactRevision) {
            throw $this->conflict('The artifact revision has changed.');
        }

        $revisionNumber = $artifact->nextRevisionNumber;
        if ($parentRevisionId !== null) {
            $parent = $this->revisionById($tenantId, $artifactId, $parentRevisionId, true);
            if ($parent === null || !$parent->isFinalized()) {
                throw $this->notFound('The artifact parent revision is unavailable.');
            }
            if ($parent->revisionNumber >= $revisionNumber) {
                throw $this->conflict('The artifact parent revision is not earlier than the new revision.');
            }
        }

        try {
            $this->execute(<<<'SQL'
INSERT INTO pa_artifact_revision (
  tenant_id, artifact_id, revision_key, revision_number, parent_revision_id,
  state, revision, payload_schema_key, payload_schema_version, payload_ref,
  payload_sha256, attachment_manifest_sha256, canonical_envelope_json,
  canonical_envelope_sha256, created_by_member_id, finalized_by_member_id,
  created_at, finalized_at
) VALUES (
  :tenant_id, :artifact_id, :revision_key, :revision_number, :parent_revision_id,
  'pending', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
  :member_id, NULL, :created_at, NULL
)
SQL, [
                'tenant_id' => $tenantId,
                'artifact_id' => $artifactId,
                'revision_key' => $revisionKey,
                'revision_number' => $revisionNumber,
                'parent_revision_id' => $parentRevisionId,
                'member_id' => $memberId,
                'created_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicate($exception)) {
                throw $this->conflict('The artifact revision identity already exists.');
            }
            throw $exception;
        }

        $updated = $this->execute(<<<'SQL'
UPDATE pa_artifact
SET revision = revision + 1, next_revision_number = next_revision_number + 1,
    updated_by_member_id = :member_id, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :artifact_id AND revision = :expected_revision
SQL, [
            'member_id' => $memberId,
            'updated_at' => $now,
            'tenant_id' => $tenantId,
            'artifact_id' => $artifactId,
            'expected_revision' => $expectedArtifactRevision,
        ]);
        if ($updated !== 1) {
            throw $this->conflict('The artifact revision has changed.');
        }

        return $this->revisionByKey($tenantId, $artifactId, $revisionKey)
            ?? throw new RuntimeException('The pending artifact revision could not be read back.');
    }

    public function revision(
        int $tenantId,
        string $artifactType,
        string $artifactKey,
        string $revisionKey,
        bool $forUpdate = false,
    ): ?ArtifactRevision {
        $row = $this->fetchOne(<<<'SQL'
SELECT r.*, a.artifact_type, a.artifact_key, p.revision_key AS parent_revision_key
FROM pa_artifact_revision r
INNER JOIN pa_artifact a
  ON a.tenant_id = r.tenant_id AND a.id = r.artifact_id
LEFT JOIN pa_artifact_revision p
  ON p.tenant_id = r.tenant_id AND p.artifact_id = r.artifact_id AND p.id = r.parent_revision_id
WHERE r.tenant_id = :tenant_id AND a.artifact_type = :artifact_type
  AND a.artifact_key = :artifact_key AND r.revision_key = :revision_key
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'artifact_type' => $artifactType,
            'artifact_key' => $artifactKey,
            'revision_key' => $revisionKey,
        ]);

        return $this->modelFromRow($row);
    }

    public function revisionById(
        int $tenantId,
        int $artifactId,
        int $revisionId,
        bool $forUpdate = false,
    ): ?ArtifactRevision {
        $row = $this->fetchOne(<<<'SQL'
SELECT r.*, a.artifact_type, a.artifact_key, p.revision_key AS parent_revision_key
FROM pa_artifact_revision r
INNER JOIN pa_artifact a
  ON a.tenant_id = r.tenant_id AND a.id = r.artifact_id
LEFT JOIN pa_artifact_revision p
  ON p.tenant_id = r.tenant_id AND p.artifact_id = r.artifact_id AND p.id = r.parent_revision_id
WHERE r.tenant_id = :tenant_id AND r.artifact_id = :artifact_id AND r.id = :revision_id
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'artifact_id' => $artifactId,
            'revision_id' => $revisionId,
        ]);

        return $this->modelFromRow($row);
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
        $artifact = $this->artifactById($tenantId, $artifactId, true)
            ?? throw $this->notFound('The artifact is unavailable.');
        if ($artifact->revision !== $expectedArtifactRevision) {
            throw $this->conflict('The artifact revision has changed.');
        }

        $revision = $this->revisionByKey($tenantId, $artifactId, $revisionKey, true)
            ?? throw $this->notFound('The artifact revision is unavailable.');
        if ($revision->isFinalized()) {
            throw $this->conflict('The artifact revision is immutable.');
        }
        if ($revision->revision !== $expectedRevision) {
            throw $this->conflict('The artifact revision has changed.');
        }

        if ($revision->parentRevisionId !== null) {
            $parent = $this->revisionById($tenantId, $artifactId, $revision->parentRevisionId, true);
            if ($parent === null
                || !$parent->isFinalized()
                || $parent->revisionNumber >= $revision->revisionNumber) {
                throw new UnexpectedValueException('The artifact revision parent lineage is invalid.');
            }
        }

        $envelope = [
            'artifact_type' => $artifact->artifactType,
            'artifact_key' => $artifact->artifactKey,
            'revision_key' => $revision->revisionKey,
            'revision_number' => $revision->revisionNumber,
            'parent_revision_key' => $revision->parentRevisionKey,
            'payload_schema_key' => $payloadSchemaKey,
            'payload_schema_version' => $payloadSchemaVersion,
            'payload_ref' => $payloadRef,
            'payload_sha256' => $payloadSha256,
            'attachment_manifest_sha256' => $attachmentManifestSha256,
        ];
        $canonicalJson = ArtifactRevision::encodeEnvelope($envelope);
        $canonicalSha256 = hash('sha256', $canonicalJson);

        $updated = $this->execute(<<<'SQL'
UPDATE pa_artifact_revision
SET state = 'finalized', revision = revision + 1,
    payload_schema_key = :payload_schema_key,
    payload_schema_version = :payload_schema_version,
    payload_ref = :payload_ref, payload_sha256 = :payload_sha256,
    attachment_manifest_sha256 = :attachment_manifest_sha256,
    canonical_envelope_json = :canonical_envelope_json,
    canonical_envelope_sha256 = :canonical_envelope_sha256,
    finalized_by_member_id = :member_id, finalized_at = :finalized_at
WHERE tenant_id = :tenant_id AND artifact_id = :artifact_id
  AND revision_key = :revision_key AND state = 'pending' AND revision = :expected_revision
SQL, [
            'payload_schema_key' => $payloadSchemaKey,
            'payload_schema_version' => $payloadSchemaVersion,
            'payload_ref' => $payloadRef,
            'payload_sha256' => $payloadSha256,
            'attachment_manifest_sha256' => $attachmentManifestSha256,
            'canonical_envelope_json' => $canonicalJson,
            'canonical_envelope_sha256' => $canonicalSha256,
            'member_id' => $memberId,
            'finalized_at' => $now,
            'tenant_id' => $tenantId,
            'artifact_id' => $artifactId,
            'revision_key' => $revisionKey,
            'expected_revision' => $expectedRevision,
        ]);
        if ($updated !== 1) {
            throw $this->conflict('The artifact revision has changed.');
        }

        $advanceLatest = true;
        if ($artifact->latestFinalizedRevisionId !== null) {
            $latest = $this->revisionById($tenantId, $artifactId, $artifact->latestFinalizedRevisionId, true);
            if ($latest === null || !$latest->isFinalized()) {
                throw new UnexpectedValueException('The latest artifact revision pointer is invalid.');
            }
            $advanceLatest = $revision->revisionNumber > $latest->revisionNumber;
        }
        $updatedArtifact = $this->execute(<<<'SQL'
UPDATE pa_artifact
SET revision = revision + 1,
    latest_finalized_revision_id = :latest_finalized_revision_id,
    updated_by_member_id = :member_id, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :artifact_id AND revision = :expected_revision
SQL, [
            'latest_finalized_revision_id' => $advanceLatest
                ? $revision->id
                : $artifact->latestFinalizedRevisionId,
            'member_id' => $memberId,
            'updated_at' => $now,
            'tenant_id' => $tenantId,
            'artifact_id' => $artifactId,
            'expected_revision' => $expectedArtifactRevision,
        ]);
        if ($updatedArtifact !== 1) {
            throw $this->conflict('The artifact revision has changed.');
        }

        return $this->revisionByKey($tenantId, $artifactId, $revisionKey)
            ?? throw new RuntimeException('The finalized artifact revision could not be read back.');
    }

    /** @param array<string, mixed>|null $row */
    private function modelFromRow(?array $row): ?ArtifactRevision
    {
        if ($row === null) {
            return null;
        }
        $model = ArtifactRevision::fromRow($row);
        if ($model->isFinalized()) {
            try {
                $model->assertEnvelopeIntegrity();
            } catch (UnexpectedValueException $exception) {
                throw new UnexpectedValueException('Artifact revision integrity failure.', 0, $exception);
            }
        }

        return $model;
    }

    private function revisionByKey(
        int $tenantId,
        int $artifactId,
        string $revisionKey,
        bool $forUpdate = false,
    ): ?ArtifactRevision {
        $row = $this->fetchOne(<<<'SQL'
SELECT r.*, a.artifact_type, a.artifact_key, p.revision_key AS parent_revision_key
FROM pa_artifact_revision r
INNER JOIN pa_artifact a
  ON a.tenant_id = r.tenant_id AND a.id = r.artifact_id
LEFT JOIN pa_artifact_revision p
  ON p.tenant_id = r.tenant_id AND p.artifact_id = r.artifact_id AND p.id = r.parent_revision_id
WHERE r.tenant_id = :tenant_id AND r.artifact_id = :artifact_id AND r.revision_key = :revision_key
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'artifact_id' => $artifactId,
            'revision_key' => $revisionKey,
        ]);

        return $this->modelFromRow($row);
    }

    private function artifactById(int $tenantId, int $artifactId, bool $forUpdate = false): ?Artifact
    {
        $row = $this->fetchOne(<<<'SQL'
SELECT * FROM pa_artifact
WHERE tenant_id = :tenant_id AND id = :artifact_id
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'artifact_id' => $artifactId,
        ]);

        return $row === null ? null : Artifact::fromRow($row);
    }

    /** @param array<string, int|string|null> $parameters */
    private function fetchOne(string $sql, array $parameters): ?array
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, int|string|null> $parameters */
    private function execute(string $sql, array $parameters): int
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Could not prepare artifact revision statement.');
        }

        return $statement;
    }

    private function isDuplicate(PDOException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? $exception->getCode()) === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    private function conflict(string $message): RuntimeException
    {
        return new RuntimeException($message);
    }

    private function notFound(string $message): RuntimeException
    {
        return new RuntimeException($message);
    }
}
