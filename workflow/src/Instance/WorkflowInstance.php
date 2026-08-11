<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Instance;

final readonly class WorkflowInstance
{
    public function __construct(
        public int $id,
        public string $instanceKey,
        public int $tenantId,
        public int $definitionId,
        public int $definitionVersion,
        public string $subjectType,
        public string $subjectKey,
        public string $subjectRevisionKey,
        public string $subjectRevisionSha256,
        public string $currentNodeKey,
        public string $status,
        public int $initiatedByMemberId,
        public ?int $lastActorMemberId,
        public int $revision,
        public string $createdAt,
        public string $updatedAt,
        public ?string $completedAt,
        public ?string $cancelledAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['instance_key'],
            (int) $row['tenant_id'],
            (int) $row['definition_id'],
            (int) $row['definition_version'],
            (string) $row['subject_type'],
            (string) $row['subject_key'],
            (string) $row['subject_revision_key'],
            (string) $row['subject_revision_sha256'],
            (string) $row['current_node_key'],
            (string) $row['status'],
            (int) $row['initiated_by_member_id'],
            $row['last_actor_member_id'] === null ? null : (int) $row['last_actor_member_id'],
            (int) $row['revision'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            $row['completed_at'] === null ? null : (string) $row['completed_at'],
            $row['cancelled_at'] === null ? null : (string) $row['cancelled_at'],
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'instance_key' => $this->instanceKey,
            'definition_id' => $this->definitionId,
            'definition_version' => $this->definitionVersion,
            'subject_type' => $this->subjectType,
            'subject_key' => $this->subjectKey,
            'subject_revision_key' => $this->subjectRevisionKey,
            'subject_revision_sha256' => $this->subjectRevisionSha256,
            'current_node_key' => $this->currentNodeKey,
            'status' => $this->status,
            'initiated_by_member_id' => $this->initiatedByMemberId,
            'last_actor_member_id' => $this->lastActorMemberId,
            'revision' => $this->revision,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'completed_at' => $this->completedAt,
            'cancelled_at' => $this->cancelledAt,
        ];
    }
}
