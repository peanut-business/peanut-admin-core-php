<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Persistence;

use PDO;
use PDOException;
use PeanutAdmin\Workflow\Application\WorkflowException;
use PeanutAdmin\Workflow\Definition\WorkflowDefinition;
use PeanutAdmin\Workflow\Definition\WorkflowDefinitionVersion;
use PeanutAdmin\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Workflow\Instance\WorkflowEvent;
use PeanutAdmin\Workflow\Instance\WorkflowInstance;
use PeanutAdmin\Workflow\Instance\WorkflowWorkItem;

final readonly class PdoWorkflowRepository implements WorkflowRepository
{
    public function __construct(private PDO $pdo) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function definition(int $tenantId, string $moduleKey, string $workflowKey, bool $forUpdate = false): ?WorkflowDefinition
    {
        $row = $this->one(<<<'SQL'
SELECT * FROM pa_workflow_definition
WHERE tenant_id = :tenant_id AND module_key = :module_key AND workflow_key = :workflow_key
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'module_key' => $moduleKey,
            'workflow_key' => $workflowKey,
        ]);

        return $row === null ? null : WorkflowDefinition::fromRow($row);
    }

    public function saveDraft(
        int $tenantId,
        int $memberId,
        string $moduleKey,
        string $workflowKey,
        WorkflowGraph $graph,
        ?int $expectedRevision,
        string $now,
    ): WorkflowDefinition {
        $definition = $this->definition($tenantId, $moduleKey, $workflowKey, true);
        if ($definition === null) {
            if ($expectedRevision !== null) {
                throw WorkflowException::definitionConflict();
            }
            try {
                $this->execute(<<<'SQL'
INSERT INTO pa_workflow_definition (
  tenant_id, module_key, workflow_key, status, draft_graph_json,
  draft_graph_sha256, latest_version, revision, created_by_member_id,
  updated_by_member_id, created_at, updated_at, retired_at
) VALUES (
  :tenant_id, :module_key, :workflow_key, 'draft', :graph_json,
  :graph_sha256, 0, 1, :member_id, :member_id, :created_at, :updated_at, NULL
)
SQL, [
                    'tenant_id' => $tenantId,
                    'module_key' => $moduleKey,
                    'workflow_key' => $workflowKey,
                    'graph_json' => $graph->canonicalJson,
                    'graph_sha256' => $graph->sha256,
                    'member_id' => $memberId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (PDOException $exception) {
                if ($this->duplicate($exception)) {
                    throw WorkflowException::definitionConflict();
                }
                throw $exception;
            }
        } else {
            if ($definition->status === 'retired') {
                throw WorkflowException::definitionRetired();
            }
            if ($expectedRevision === null) {
                throw WorkflowException::preconditionRequired();
            }
            if ($definition->revision !== $expectedRevision) {
                throw WorkflowException::definitionConflict();
            }
            $updated = $this->execute(<<<'SQL'
UPDATE pa_workflow_definition
SET draft_graph_json = :graph_json, draft_graph_sha256 = :graph_sha256,
    revision = revision + 1, updated_by_member_id = :member_id, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :id AND revision = :expected_revision AND status <> 'retired'
SQL, [
                'graph_json' => $graph->canonicalJson,
                'graph_sha256' => $graph->sha256,
                'member_id' => $memberId,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'id' => $definition->id,
                'expected_revision' => $expectedRevision,
            ]);
            if ($updated !== 1) {
                throw WorkflowException::definitionConflict();
            }
        }

        return $this->definition($tenantId, $moduleKey, $workflowKey)
            ?? throw WorkflowException::internal();
    }

    public function publishDefinition(
        int $tenantId,
        int $memberId,
        string $moduleKey,
        string $workflowKey,
        int $expectedRevision,
        string $now,
    ): array {
        $definition = $this->definition($tenantId, $moduleKey, $workflowKey, true);
        if ($definition === null) {
            throw WorkflowException::subjectNotFound();
        }
        if ($definition->status === 'retired') {
            throw WorkflowException::definitionRetired();
        }
        if ($definition->revision !== $expectedRevision) {
            throw WorkflowException::definitionConflict();
        }
        $version = $definition->latestVersion + 1;
        try {
            $this->execute(<<<'SQL'
INSERT INTO pa_workflow_definition_version (
  tenant_id, definition_id, version, graph_json, graph_sha256,
  published_by_member_id, published_at
) VALUES (
  :tenant_id, :definition_id, :version, :graph_json, :graph_sha256,
  :member_id, :published_at
)
SQL, [
                'tenant_id' => $tenantId,
                'definition_id' => $definition->id,
                'version' => $version,
                'graph_json' => $definition->draftGraphJson,
                'graph_sha256' => $definition->draftGraphSha256,
                'member_id' => $memberId,
                'published_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if ($this->duplicate($exception)) {
                throw WorkflowException::definitionConflict();
            }
            throw $exception;
        }
        $updated = $this->execute(<<<'SQL'
UPDATE pa_workflow_definition
SET status = 'active', latest_version = :version, revision = revision + 1,
    updated_by_member_id = :member_id, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :id AND revision = :expected_revision AND status <> 'retired'
SQL, [
            'version' => $version,
            'member_id' => $memberId,
            'updated_at' => $now,
            'tenant_id' => $tenantId,
            'id' => $definition->id,
            'expected_revision' => $expectedRevision,
        ]);
        if ($updated !== 1) {
            throw WorkflowException::definitionConflict();
        }

        return [
            'definition' => $this->definition($tenantId, $moduleKey, $workflowKey)
                ?? throw WorkflowException::internal(),
            'version' => $this->definitionVersion($tenantId, $definition->id, $version)
                ?? throw WorkflowException::internal(),
        ];
    }

    public function retireDefinition(
        int $tenantId,
        int $memberId,
        string $moduleKey,
        string $workflowKey,
        int $expectedRevision,
        string $now,
    ): WorkflowDefinition {
        $definition = $this->definition($tenantId, $moduleKey, $workflowKey, true);
        if ($definition === null) {
            throw WorkflowException::subjectNotFound();
        }
        if ($definition->status === 'retired') {
            throw WorkflowException::definitionRetired();
        }
        if ($definition->status !== 'active') {
            throw WorkflowException::transitionUnavailable();
        }
        if ($definition->revision !== $expectedRevision) {
            throw WorkflowException::definitionConflict();
        }
        $updated = $this->execute(<<<'SQL'
UPDATE pa_workflow_definition
SET status = 'retired', revision = revision + 1, updated_by_member_id = :member_id,
    updated_at = :updated_at, retired_at = :retired_at
WHERE tenant_id = :tenant_id AND id = :id AND revision = :expected_revision AND status = 'active'
SQL, [
            'member_id' => $memberId,
            'updated_at' => $now,
            'retired_at' => $now,
            'tenant_id' => $tenantId,
            'id' => $definition->id,
            'expected_revision' => $expectedRevision,
        ]);
        if ($updated !== 1) {
            throw WorkflowException::definitionConflict();
        }

        return $this->definition($tenantId, $moduleKey, $workflowKey)
            ?? throw WorkflowException::internal();
    }

    public function definitionVersion(int $tenantId, int $definitionId, int $version): ?WorkflowDefinitionVersion
    {
        $row = $this->one(<<<'SQL'
SELECT * FROM pa_workflow_definition_version
WHERE tenant_id = :tenant_id AND definition_id = :definition_id AND version = :version
SQL, ['tenant_id' => $tenantId, 'definition_id' => $definitionId, 'version' => $version]);

        return $row === null ? null : WorkflowDefinitionVersion::fromRow($row);
    }

    public function createInstance(
        int $tenantId,
        int $definitionId,
        int $definitionVersion,
        string $instanceKey,
        string $subjectType,
        string $subjectKey,
        string $subjectRevisionKey,
        string $subjectRevisionSha256,
        string $currentNodeKey,
        int $initiatedByMemberId,
        string $now,
    ): WorkflowInstance {
        try {
            $this->execute(<<<'SQL'
INSERT INTO pa_workflow_instance (
  instance_key, tenant_id, definition_id, definition_version, subject_type,
  subject_key, subject_revision_key, subject_revision_sha256, current_node_key,
  status, initiated_by_member_id, last_actor_member_id, revision, created_at,
  updated_at, completed_at, cancelled_at
) VALUES (
  :instance_key, :tenant_id, :definition_id, :definition_version, :subject_type,
  :subject_key, :subject_revision_key, :subject_revision_sha256, :current_node_key,
  'active', :member_id, :member_id, 1, :created_at, :updated_at, NULL, NULL
)
SQL, [
                'instance_key' => $instanceKey,
                'tenant_id' => $tenantId,
                'definition_id' => $definitionId,
                'definition_version' => $definitionVersion,
                'subject_type' => $subjectType,
                'subject_key' => $subjectKey,
                'subject_revision_key' => $subjectRevisionKey,
                'subject_revision_sha256' => $subjectRevisionSha256,
                'current_node_key' => $currentNodeKey,
                'member_id' => $initiatedByMemberId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if ($this->duplicate($exception)) {
                throw WorkflowException::instanceConflict();
            }
            throw $exception;
        }

        return $this->instance($tenantId, $instanceKey)
            ?? throw WorkflowException::internal();
    }

    public function instance(int $tenantId, string $instanceKey, bool $forUpdate = false): ?WorkflowInstance
    {
        $row = $this->one(<<<'SQL'
SELECT * FROM pa_workflow_instance
WHERE tenant_id = :tenant_id AND instance_key = :instance_key
SQL . ($forUpdate ? ' FOR UPDATE' : ''), ['tenant_id' => $tenantId, 'instance_key' => $instanceKey]);

        return $row === null ? null : WorkflowInstance::fromRow($row);
    }

    public function pendingWorkItems(int $tenantId, int $instanceId, string $nodeKey, bool $forUpdate = false): array
    {
        $rows = $this->all(<<<'SQL'
SELECT * FROM pa_workflow_work_item
WHERE tenant_id = :tenant_id AND instance_id = :instance_id
  AND node_key = :node_key AND status = 'pending'
ORDER BY id ASC
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'instance_id' => $instanceId,
            'node_key' => $nodeKey,
        ]);

        return array_map(WorkflowWorkItem::fromRow(...), $rows);
    }

    public function createWorkItems(
        int $tenantId,
        int $instanceId,
        string $nodeKey,
        int $roundNo,
        array $assignments,
        string $now,
    ): array {
        $items = [];
        foreach ($assignments as $assignment) {
            $this->execute(<<<'SQL'
INSERT INTO pa_workflow_work_item (
  work_item_key, tenant_id, instance_id, node_key, round_no,
  assignment_source_kind, assignment_source_key, assignee_member_id,
  status, decision, completed_by_member_id, revision, created_at, updated_at,
  completed_at, cancelled_at
) VALUES (
  :work_item_key, :tenant_id, :instance_id, :node_key, :round_no,
  :source_kind, :source_key, :member_id, 'pending', NULL, NULL, 1,
  :created_at, :updated_at, NULL, NULL
)
SQL, [
                'work_item_key' => $assignment['work_item_key'],
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
                'node_key' => $nodeKey,
                'round_no' => $roundNo,
                'source_kind' => $assignment['source_kind'],
                'source_key' => $assignment['source_key'],
                'member_id' => $assignment['member_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = $this->one('SELECT * FROM pa_workflow_work_item WHERE work_item_key = :work_item_key', [
                'work_item_key' => $assignment['work_item_key'],
            ]);
            $items[] = $row === null ? throw WorkflowException::internal() : WorkflowWorkItem::fromRow($row);
        }

        return $items;
    }

    public function nextRound(int $tenantId, int $instanceId, string $nodeKey): int
    {
        $row = $this->one(<<<'SQL'
SELECT COALESCE(MAX(round_no), 0) AS round_no FROM pa_workflow_work_item
WHERE tenant_id = :tenant_id AND instance_id = :instance_id AND node_key = :node_key
SQL, ['tenant_id' => $tenantId, 'instance_id' => $instanceId, 'node_key' => $nodeKey]);

        return ((int) ($row['round_no'] ?? 0)) + 1;
    }

    public function completeWorkItem(int $tenantId, int $id, int $memberId, string $decision, string $now): void
    {
        $updated = $this->execute(<<<'SQL'
UPDATE pa_workflow_work_item
SET status = 'completed', decision = :decision, completed_by_member_id = :member_id,
    revision = revision + 1, updated_at = :updated_at, completed_at = :completed_at
WHERE tenant_id = :tenant_id AND id = :id AND assignee_member_id = :member_id AND status = 'pending'
SQL, [
            'decision' => $decision,
            'member_id' => $memberId,
            'updated_at' => $now,
            'completed_at' => $now,
            'tenant_id' => $tenantId,
            'id' => $id,
        ]);
        if ($updated !== 1) {
            throw WorkflowException::assignmentDenied();
        }
    }

    public function cancelPendingWorkItems(int $tenantId, int $instanceId, string $nodeKey, ?int $exceptId, string $now): void
    {
        $sql = <<<'SQL'
UPDATE pa_workflow_work_item
SET status = 'cancelled', revision = revision + 1, updated_at = :updated_at,
    cancelled_at = :cancelled_at
WHERE tenant_id = :tenant_id AND instance_id = :instance_id
  AND node_key = :node_key AND status = 'pending'
SQL;
        $parameters = [
            'updated_at' => $now,
            'cancelled_at' => $now,
            'tenant_id' => $tenantId,
            'instance_id' => $instanceId,
            'node_key' => $nodeKey,
        ];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $parameters['except_id'] = $exceptId;
        }
        $this->execute($sql, $parameters);
    }

    public function completedDecisions(int $tenantId, int $instanceId, string $nodeKey, int $roundNo): array
    {
        $rows = $this->all(<<<'SQL'
SELECT decision FROM pa_workflow_work_item
WHERE tenant_id = :tenant_id AND instance_id = :instance_id
  AND node_key = :node_key AND round_no = :round_no AND status = 'completed'
ORDER BY id ASC
SQL, [
            'tenant_id' => $tenantId,
            'instance_id' => $instanceId,
            'node_key' => $nodeKey,
            'round_no' => $roundNo,
        ]);

        return array_map(static fn(array $row): string => (string) $row['decision'], $rows);
    }

    public function transitionInstance(
        WorkflowInstance $instance,
        string $toNodeKey,
        string $status,
        ?int $lastActorMemberId,
        string $now,
    ): WorkflowInstance {
        $completedAt = $status === 'completed' ? $now : null;
        $cancelledAt = $status === 'cancelled' ? $now : null;
        $updated = $this->execute(<<<'SQL'
UPDATE pa_workflow_instance
SET current_node_key = :current_node_key, status = :status,
    last_actor_member_id = :last_actor_member_id, revision = revision + 1,
    updated_at = :updated_at, completed_at = :completed_at, cancelled_at = :cancelled_at
WHERE tenant_id = :tenant_id AND id = :id AND status = 'active' AND revision = :expected_revision
SQL, [
            'current_node_key' => $toNodeKey,
            'status' => $status,
            'last_actor_member_id' => $lastActorMemberId,
            'updated_at' => $now,
            'completed_at' => $completedAt,
            'cancelled_at' => $cancelledAt,
            'tenant_id' => $instance->tenantId,
            'id' => $instance->id,
            'expected_revision' => $instance->revision,
        ]);
        if ($updated !== 1) {
            throw WorkflowException::instanceConflict();
        }

        return $this->instance($instance->tenantId, $instance->instanceKey)
            ?? throw WorkflowException::internal();
    }

    public function nextEventSequence(int $tenantId, int $instanceId): int
    {
        $row = $this->one(<<<'SQL'
SELECT COALESCE(MAX(sequence_no), 0) AS sequence_no FROM pa_workflow_event
WHERE tenant_id = :tenant_id AND instance_id = :instance_id
SQL, ['tenant_id' => $tenantId, 'instance_id' => $instanceId]);

        return ((int) ($row['sequence_no'] ?? 0)) + 1;
    }

    public function appendEvent(
        int $tenantId,
        int $instanceId,
        int $sequenceNo,
        string $eventKey,
        ?string $transitionKey,
        ?string $fromNodeKey,
        string $toNodeKey,
        string $actorType,
        ?int $actorMemberId,
        string $subjectRevisionKey,
        string $subjectRevisionSha256,
        ?string $comment,
        string $attachmentSnapshotsJson,
        string $metadataJson,
        string $now,
    ): WorkflowEvent {
        $commentSha256 = $comment === null ? null : hash('sha256', $comment);
        $this->execute(<<<'SQL'
INSERT INTO pa_workflow_event (
  tenant_id, instance_id, sequence_no, event_key, transition_key,
  from_node_key, to_node_key, actor_type, actor_member_id,
  subject_revision_key, subject_revision_sha256, comment_text, comment_sha256,
  attachment_snapshots_json, metadata_json, occurred_at
) VALUES (
  :tenant_id, :instance_id, :sequence_no, :event_key, :transition_key,
  :from_node_key, :to_node_key, :actor_type, :actor_member_id,
  :subject_revision_key, :subject_revision_sha256, :comment_text, :comment_sha256,
  :attachment_snapshots_json, :metadata_json, :occurred_at
)
SQL, [
            'tenant_id' => $tenantId,
            'instance_id' => $instanceId,
            'sequence_no' => $sequenceNo,
            'event_key' => $eventKey,
            'transition_key' => $transitionKey,
            'from_node_key' => $fromNodeKey,
            'to_node_key' => $toNodeKey,
            'actor_type' => $actorType,
            'actor_member_id' => $actorMemberId,
            'subject_revision_key' => $subjectRevisionKey,
            'subject_revision_sha256' => $subjectRevisionSha256,
            'comment_text' => $comment,
            'comment_sha256' => $commentSha256,
            'attachment_snapshots_json' => $attachmentSnapshotsJson,
            'metadata_json' => $metadataJson,
            'occurred_at' => $now,
        ]);
        $row = $this->one(<<<'SQL'
SELECT * FROM pa_workflow_event
WHERE tenant_id = :tenant_id AND instance_id = :instance_id AND sequence_no = :sequence_no
SQL, ['tenant_id' => $tenantId, 'instance_id' => $instanceId, 'sequence_no' => $sequenceNo]);

        return $row === null ? throw WorkflowException::internal() : WorkflowEvent::fromRow($row);
    }

    public function transitionTraversalCount(int $tenantId, int $instanceId, string $transitionKey): int
    {
        $row = $this->one(<<<'SQL'
SELECT COUNT(*) AS traversal_count FROM pa_workflow_event
WHERE tenant_id = :tenant_id AND instance_id = :instance_id AND transition_key = :transition_key
SQL, ['tenant_id' => $tenantId, 'instance_id' => $instanceId, 'transition_key' => $transitionKey]);

        return (int) ($row['traversal_count'] ?? 0);
    }

    public function definitions(int $tenantId, ?string $status, int $page, int $pageSize): array
    {
        $sql = 'SELECT * FROM pa_workflow_definition WHERE tenant_id = :tenant_id';
        $parameters = ['tenant_id' => $tenantId];
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $parameters['status'] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize);

        return array_map(WorkflowDefinition::fromRow(...), $this->all($sql, $parameters));
    }

    public function versions(int $tenantId, int $definitionId): array
    {
        return array_map(WorkflowDefinitionVersion::fromRow(...), $this->all(<<<'SQL'
SELECT * FROM pa_workflow_definition_version
WHERE tenant_id = :tenant_id AND definition_id = :definition_id
ORDER BY version ASC
SQL, ['tenant_id' => $tenantId, 'definition_id' => $definitionId]));
    }

    public function workItems(int $tenantId, int $instanceId, ?string $status, int $page, int $pageSize): array
    {
        $sql = 'SELECT * FROM pa_workflow_work_item WHERE tenant_id = :tenant_id AND instance_id = :instance_id';
        $parameters = ['tenant_id' => $tenantId, 'instance_id' => $instanceId];
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $parameters['status'] = $status;
        }
        $sql .= ' ORDER BY id ASC LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize);

        return array_map(WorkflowWorkItem::fromRow(...), $this->all($sql, $parameters));
    }

    public function events(int $tenantId, int $instanceId, int $afterSequence, int $pageSize): array
    {
        return array_map(WorkflowEvent::fromRow(...), $this->all(<<<SQL
SELECT * FROM pa_workflow_event
WHERE tenant_id = :tenant_id AND instance_id = :instance_id AND sequence_no > :after_sequence
ORDER BY id ASC LIMIT {$pageSize}
SQL, ['tenant_id' => $tenantId, 'instance_id' => $instanceId, 'after_sequence' => $afterSequence]));
    }

    /** @param array<string, int|string|null> $parameters @return array<string, mixed>|null */
    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, int|string|null> $parameters @return list<array<string, mixed>> */
    private function all(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_filter($rows, 'is_array'));
    }

    /** @param array<string, int|string|null> $parameters */
    private function execute(string $sql, array $parameters): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    private function duplicate(PDOException $exception): bool
    {
        return $exception->getCode() === '23000' && ($exception->errorInfo[1] ?? null) === 1062;
    }
}
