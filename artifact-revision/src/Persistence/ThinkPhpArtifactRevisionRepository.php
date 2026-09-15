<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Persistence;

use PeanutAdmin\ArtifactRevision\Model\Artifact;
use PeanutAdmin\ArtifactRevision\Model\ArtifactRevision;
use PeanutAdmin\ArtifactRevision\Persistence\Model\ArtifactRecord;
use PeanutAdmin\ArtifactRevision\Persistence\Model\ArtifactRevisionRecord;
use PeanutAdmin\ArtifactRevision\Workflow\ArtifactSubjectRevisionReader;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use RuntimeException;
use think\db\exception\PDOException;
use think\db\Raw;
use UnexpectedValueException;

/** ThinkORM persistence for the artifact aggregate's CAS and immutable-lineage rules. */
final readonly class ThinkPhpArtifactRevisionRepository implements ArtifactSubjectRevisionReader
{
    public function artifact(
        int $tenantId,
        string $artifactType,
        string $artifactKey,
        bool $forUpdate = false,
    ): ?Artifact {
        $query = ArtifactRecord::scope('tenant', $this->scope($tenantId))
            ->where('artifact_type', $artifactType)
            ->where('artifact_key', $artifactKey);
        if ($forUpdate) {
            $query->lock(true);
        }

        $record = $query->find();

        return $record instanceof ArtifactRecord ? Artifact::fromRow($record->toArray()) : null;
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
            (new ArtifactRecord())->save([
                'tenant_id' => $tenantId,
                'artifact_type' => $artifactType,
                'artifact_key' => $artifactKey,
                'revision' => 1,
                'next_revision_number' => 1,
                'latest_finalized_revision_id' => null,
                'created_by_member_id' => $memberId,
                'updated_by_member_id' => $memberId,
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
        $parentRevisionNumber = null;
        if ($parentRevisionId !== null) {
            $parent = $this->revisionById($tenantId, $artifactId, $parentRevisionId, true);
            if ($parent === null || !$parent->isFinalized()) {
                throw $this->notFound('The artifact parent revision is unavailable.');
            }
            if ($parent->revisionNumber >= $revisionNumber) {
                throw $this->conflict('The artifact parent revision is not earlier than the new revision.');
            }
            $parentRevisionNumber = $parent->revisionNumber;
        }

        try {
            (new ArtifactRevisionRecord())->save([
                'tenant_id' => $tenantId,
                'artifact_id' => $artifactId,
                'revision_key' => $revisionKey,
                'revision_number' => $revisionNumber,
                'parent_revision_id' => $parentRevisionId,
                'parent_revision_number' => $parentRevisionNumber,
                'state' => 'pending',
                'revision' => 1,
                'payload_schema_key' => null,
                'payload_schema_version' => null,
                'payload_ref' => null,
                'payload_sha256' => null,
                'attachment_manifest_sha256' => null,
                'canonical_envelope_json' => null,
                'canonical_envelope_sha256' => null,
                'created_by_member_id' => $memberId,
                'finalized_by_member_id' => null,
                'created_at' => $now,
                'finalized_at' => null,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicate($exception)) {
                throw $this->conflict('The artifact revision identity already exists.');
            }
            throw $exception;
        }

        $updated = ArtifactRecord::scope('tenant', $this->scope($tenantId))
            ->where('id', $artifactId)
            ->where('revision', $expectedArtifactRevision)
            ->update([
                'revision' => new Raw('revision + 1'),
                'next_revision_number' => new Raw('next_revision_number + 1'),
                'updated_by_member_id' => $memberId,
                'updated_at' => $now,
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
        $artifact = $this->artifact($tenantId, $artifactType, $artifactKey, $forUpdate);

        return $artifact === null
            ? null
            : $this->revisionByKey($tenantId, $artifact->id, $revisionKey, $forUpdate);
    }

    public function revisionById(
        int $tenantId,
        int $artifactId,
        int $revisionId,
        bool $forUpdate = false,
    ): ?ArtifactRevision {
        $artifact = $this->artifactById($tenantId, $artifactId, $forUpdate);
        if ($artifact === null) {
            return null;
        }
        $query = ArtifactRevisionRecord::scope('tenant', $this->scope($tenantId))
            ->where('artifact_id', $artifactId)
            ->where('id', $revisionId);
        if ($forUpdate) {
            $query->lock(true);
        }
        $record = $query->find();

        return $record instanceof ArtifactRevisionRecord
            ? $this->revisionFromRecord($artifact, $record, $forUpdate)
            : null;
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

        $canonicalJson = ArtifactRevision::encodeEnvelope([
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
        ]);
        $updated = ArtifactRevisionRecord::scope('tenant', $this->scope($tenantId))
            ->where('artifact_id', $artifactId)
            ->where('revision_key', $revisionKey)
            ->where('state', 'pending')
            ->where('revision', $expectedRevision)
            ->update([
                'state' => 'finalized',
                'revision' => new Raw('revision + 1'),
                'payload_schema_key' => $payloadSchemaKey,
                'payload_schema_version' => $payloadSchemaVersion,
                'payload_ref' => $payloadRef,
                'payload_sha256' => $payloadSha256,
                'attachment_manifest_sha256' => $attachmentManifestSha256,
                'canonical_envelope_json' => $canonicalJson,
                'canonical_envelope_sha256' => hash('sha256', $canonicalJson),
                'finalized_by_member_id' => $memberId,
                'finalized_at' => $now,
            ]);
        if ($updated !== 1) {
            throw $this->conflict('The artifact revision has changed.');
        }

        $latestId = $artifact->latestFinalizedRevisionId;
        if ($latestId !== null) {
            $latest = $this->revisionById($tenantId, $artifactId, $latestId, true);
            if ($latest === null || !$latest->isFinalized()) {
                throw new UnexpectedValueException('The latest artifact revision pointer is invalid.');
            }
            if ($revision->revisionNumber > $latest->revisionNumber) {
                $latestId = $revision->id;
            }
        } else {
            $latestId = $revision->id;
        }

        $updatedArtifact = ArtifactRecord::scope('tenant', $this->scope($tenantId))
            ->where('id', $artifactId)
            ->where('revision', $expectedArtifactRevision)
            ->update([
                'revision' => new Raw('revision + 1'),
                'latest_finalized_revision_id' => $latestId,
                'updated_by_member_id' => $memberId,
                'updated_at' => $now,
            ]);
        if ($updatedArtifact !== 1) {
            throw $this->conflict('The artifact revision has changed.');
        }

        return $this->revisionByKey($tenantId, $artifactId, $revisionKey)
            ?? throw new RuntimeException('The finalized artifact revision could not be read back.');
    }

    private function revisionByKey(
        int $tenantId,
        int $artifactId,
        string $revisionKey,
        bool $forUpdate = false,
    ): ?ArtifactRevision {
        $artifact = $this->artifactById($tenantId, $artifactId, $forUpdate);
        if ($artifact === null) {
            return null;
        }
        $query = ArtifactRevisionRecord::scope('tenant', $this->scope($tenantId))
            ->where('artifact_id', $artifactId)
            ->where('revision_key', $revisionKey);
        if ($forUpdate) {
            $query->lock(true);
        }
        $record = $query->find();

        return $record instanceof ArtifactRevisionRecord
            ? $this->revisionFromRecord($artifact, $record, $forUpdate)
            : null;
    }

    private function artifactById(int $tenantId, int $artifactId, bool $forUpdate = false): ?Artifact
    {
        $query = ArtifactRecord::scope('tenant', $this->scope($tenantId))->where('id', $artifactId);
        if ($forUpdate) {
            $query->lock(true);
        }
        $record = $query->find();

        return $record instanceof ArtifactRecord ? Artifact::fromRow($record->toArray()) : null;
    }

    private function revisionFromRecord(
        Artifact $artifact,
        ArtifactRevisionRecord $record,
        bool $forUpdate,
    ): ArtifactRevision {
        $row = $record->toArray();
        $row['artifact_type'] = $artifact->artifactType;
        $row['artifact_key'] = $artifact->artifactKey;
        $row['parent_revision_key'] = null;
        $parentId = $row['parent_revision_id'] ?? null;
        if ($parentId !== null) {
            $query = ArtifactRevisionRecord::scope('tenant', $this->scope($artifact->tenantId))
                ->where('artifact_id', $artifact->id)
                ->where('id', (int) $parentId);
            if ($forUpdate) {
                $query->lock(true);
            }
            $parent = $query->find();
            if (!$parent instanceof ArtifactRevisionRecord) {
                throw new UnexpectedValueException('The artifact revision parent is unavailable.');
            }
            $row['parent_revision_key'] = (string) $parent->getAttr('revision_key');
        }

        $revision = ArtifactRevision::fromRow($row);
        if ($revision->isFinalized()) {
            try {
                $revision->assertEnvelopeIntegrity();
            } catch (UnexpectedValueException $exception) {
                throw new UnexpectedValueException('Artifact revision integrity failure.', 0, $exception);
            }
        }

        return $revision;
    }

    private function scope(int $tenantId): TenantScope
    {
        return TenantScope::fromTrustedContext($tenantId, 'artifact-revision');
    }

    private function isDuplicate(PDOException $exception): bool
    {
        $error = $exception->getData()['PDO Error Info'] ?? [];

        return (string) ($error['SQLSTATE'] ?? $exception->getCode()) === '23000'
            && (int) ($error['Driver Error Code'] ?? 0) === 1062;
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
