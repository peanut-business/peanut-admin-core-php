<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Model;

final readonly class Artifact
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $artifactType,
        public string $artifactKey,
        public int $revision,
        public int $nextRevisionNumber,
        public ?int $latestFinalizedRevisionId,
        public int $createdByMemberId,
        public int $updatedByMemberId,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['artifact_type'],
            (string) $row['artifact_key'],
            (int) $row['revision'],
            (int) $row['next_revision_number'],
            $row['latest_finalized_revision_id'] === null
                ? null
                : (int) $row['latest_finalized_revision_id'],
            (int) $row['created_by_member_id'],
            (int) $row['updated_by_member_id'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'artifact_type' => $this->artifactType,
            'artifact_key' => $this->artifactKey,
            'revision' => $this->revision,
            'next_revision_number' => $this->nextRevisionNumber,
            'latest_finalized_revision_id' => $this->latestFinalizedRevisionId,
            'created_by_member_id' => $this->createdByMemberId,
            'updated_by_member_id' => $this->updatedByMemberId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
