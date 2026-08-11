<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Persistence;

use PDO;
use PeanutAdmin\ArtifactRevision\Model\Artifact;
use PeanutAdmin\ArtifactRevision\Model\ArtifactRevision;

interface ArtifactRevisionRepository
{
    public function connection(): PDO;

    public function artifact(
        int $tenantId,
        string $artifactType,
        string $artifactKey,
        bool $forUpdate = false,
    ): ?Artifact;

    /**
     * Lock an existing Tenant artifact or insert a new identity. Existing
     * identities require their current optimistic artifact revision.
     */
    public function lockOrCreateArtifact(
        int $tenantId,
        string $artifactType,
        string $artifactKey,
        int $memberId,
        ?int $expectedRevision,
        string $now,
    ): Artifact;

    /** Reserve the next immutable revision number for a locked artifact. */
    public function createPendingRevision(
        int $tenantId,
        int $artifactId,
        string $revisionKey,
        ?int $parentRevisionId,
        int $expectedArtifactRevision,
        int $memberId,
        string $now,
    ): ArtifactRevision;

    public function revision(
        int $tenantId,
        string $artifactType,
        string $artifactKey,
        string $revisionKey,
        bool $forUpdate = false,
    ): ?ArtifactRevision;

    /** Read a revision while proving it belongs to this Tenant/artifact. */
    public function revisionById(
        int $tenantId,
        int $artifactId,
        int $revisionId,
        bool $forUpdate = false,
    ): ?ArtifactRevision;

    /**
     * Finalize exactly one pending row and atomically advance the artifact
     * optimistic revision and latest-finalized pointer.
     */
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
    ): ArtifactRevision;
}
