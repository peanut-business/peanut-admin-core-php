<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Instance;

use PeanutAdmin\Workflow\Application\WorkflowException;

final readonly class WorkflowEvent
{
    /**
     * @param list<array<string, mixed>> $attachmentSnapshots
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $instanceId,
        public int $sequenceNo,
        public string $eventKey,
        public ?string $transitionKey,
        public ?string $fromNodeKey,
        public string $toNodeKey,
        public string $actorType,
        public ?int $actorMemberId,
        public string $subjectRevisionKey,
        public string $subjectRevisionSha256,
        public ?string $commentText,
        public ?string $commentSha256,
        public array $attachmentSnapshots,
        public array $metadata,
        public string $occurredAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $attachmentSnapshots = $row['attachment_snapshots_json'] ?? null;
        $metadata = $row['metadata_json'] ?? null;
        if (!is_array($attachmentSnapshots) || !array_is_list($attachmentSnapshots)
            || !is_array($metadata) || array_is_list($metadata)) {
            throw WorkflowException::internal();
        }

        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['instance_id'],
            (int) $row['sequence_no'],
            (string) $row['event_key'],
            $row['transition_key'] === null ? null : (string) $row['transition_key'],
            $row['from_node_key'] === null ? null : (string) $row['from_node_key'],
            (string) $row['to_node_key'],
            (string) $row['actor_type'],
            $row['actor_member_id'] === null ? null : (int) $row['actor_member_id'],
            (string) $row['subject_revision_key'],
            (string) $row['subject_revision_sha256'],
            $row['comment_text'] === null ? null : (string) $row['comment_text'],
            $row['comment_sha256'] === null ? null : (string) $row['comment_sha256'],
            $attachmentSnapshots,
            $metadata,
            (string) $row['occurred_at'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sequence_no' => $this->sequenceNo,
            'event_key' => $this->eventKey,
            'transition_key' => $this->transitionKey,
            'from_node_key' => $this->fromNodeKey,
            'to_node_key' => $this->toNodeKey,
            'actor_type' => $this->actorType,
            'actor_member_id' => $this->actorMemberId,
            'subject_revision_key' => $this->subjectRevisionKey,
            'subject_revision_sha256' => $this->subjectRevisionSha256,
            'comment_text' => $this->commentText,
            'comment_sha256' => $this->commentSha256,
            'attachment_snapshots' => $this->attachmentSnapshots,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
