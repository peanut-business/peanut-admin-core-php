<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Instance;

final readonly class WorkflowWorkItem
{
    public function __construct(
        public int $id,
        public string $workItemKey,
        public int $tenantId,
        public int $instanceId,
        public string $nodeKey,
        public int $roundNo,
        public string $assignmentSourceKind,
        public string $assignmentSourceKey,
        public int $assigneeMemberId,
        public string $status,
        public ?string $decision,
        public ?int $completedByMemberId,
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
            (string) $row['work_item_key'],
            (int) $row['tenant_id'],
            (int) $row['instance_id'],
            (string) $row['node_key'],
            (int) $row['round_no'],
            (string) $row['assignment_source_kind'],
            (string) $row['assignment_source_key'],
            (int) $row['assignee_member_id'],
            (string) $row['status'],
            $row['decision'] === null ? null : (string) $row['decision'],
            $row['completed_by_member_id'] === null ? null : (int) $row['completed_by_member_id'],
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
            'work_item_key' => $this->workItemKey,
            'node_key' => $this->nodeKey,
            'round_no' => $this->roundNo,
            'assignment_source_kind' => $this->assignmentSourceKind,
            'assignment_source_key' => $this->assignmentSourceKey,
            'assignee_member_id' => $this->assigneeMemberId,
            'status' => $this->status,
            'decision' => $this->decision,
            'completed_by_member_id' => $this->completedByMemberId,
            'revision' => $this->revision,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'completed_at' => $this->completedAt,
            'cancelled_at' => $this->cancelledAt,
        ];
    }
}
