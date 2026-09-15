<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Persistence;

use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Workflow\Application\WorkflowException;
use PeanutAdmin\Workflow\Definition\WorkflowDefinition;
use PeanutAdmin\Workflow\Definition\WorkflowDefinitionVersion;
use PeanutAdmin\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Workflow\Instance\WorkflowEvent;
use PeanutAdmin\Workflow\Instance\WorkflowInstance;
use PeanutAdmin\Workflow\Instance\WorkflowWorkItem;
use PeanutAdmin\Workflow\Persistence\Model\WorkflowDefinitionRecord;
use PeanutAdmin\Workflow\Persistence\Model\WorkflowDefinitionVersionRecord;
use PeanutAdmin\Workflow\Persistence\Model\WorkflowEventRecord;
use PeanutAdmin\Workflow\Persistence\Model\WorkflowInstanceRecord;
use PeanutAdmin\Workflow\Persistence\Model\WorkflowWorkItemRecord;
use think\db\exception\PDOException;
use think\facade\Db;
use think\Model;

/** ThinkORM persistence for workflow definition, transition and event-stream semantics. */
final readonly class ThinkPhpWorkflowRepository
{
    public function definition(int $tenantId, string $moduleKey, string $workflowKey, bool $forUpdate = false): ?WorkflowDefinition
    {
        $query = WorkflowDefinitionRecord::scope('tenant', $this->scope($tenantId))
            ->where('module_key', $moduleKey)
            ->where('workflow_key', $workflowKey);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $this->mapOne($query->find(), WorkflowDefinition::fromRow(...));
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
                (new WorkflowDefinitionRecord())->save([
                    'tenant_id' => $tenantId,
                    'module_key' => $moduleKey,
                    'workflow_key' => $workflowKey,
                    'status' => 'draft',
                    'draft_graph_json' => $graph->canonicalJson,
                    'draft_graph_sha256' => $graph->sha256,
                    'latest_version' => 0,
                    'revision' => 1,
                    'created_by_member_id' => $memberId,
                    'updated_by_member_id' => $memberId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'retired_at' => null,
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
            $updated = WorkflowDefinitionRecord::scope('tenant', $this->scope($tenantId))
                ->where('id', $definition->id)
                ->where('revision', $expectedRevision)
                ->where('status', '<>', 'retired')
                ->update([
                    'draft_graph_json' => $graph->canonicalJson,
                    'draft_graph_sha256' => $graph->sha256,
                    'revision' => Db::raw('revision + 1'),
                    'updated_by_member_id' => $memberId,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw WorkflowException::definitionConflict();
            }
        }

        return $this->definition($tenantId, $moduleKey, $workflowKey)
            ?? throw WorkflowException::internal();
    }

    /** @return array{definition: WorkflowDefinition, version: WorkflowDefinitionVersion} */
    public function publishDefinition(
        int $tenantId,
        int $memberId,
        string $moduleKey,
        string $workflowKey,
        int $expectedRevision,
        string $now,
    ): array {
        $definition = $this->definition($tenantId, $moduleKey, $workflowKey, true)
            ?? throw WorkflowException::subjectNotFound();
        if ($definition->status === 'retired') {
            throw WorkflowException::definitionRetired();
        }
        if ($definition->revision !== $expectedRevision) {
            throw WorkflowException::definitionConflict();
        }
        $version = $definition->latestVersion + 1;
        try {
            (new WorkflowDefinitionVersionRecord())->save([
                'tenant_id' => $tenantId,
                'definition_id' => $definition->id,
                'version' => $version,
                'graph_json' => $definition->draftGraph,
                'graph_sha256' => $definition->draftGraphSha256,
                'published_by_member_id' => $memberId,
                'published_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if ($this->duplicate($exception)) {
                throw WorkflowException::definitionConflict();
            }
            throw $exception;
        }
        $updated = WorkflowDefinitionRecord::scope('tenant', $this->scope($tenantId))
            ->where('id', $definition->id)
            ->where('revision', $expectedRevision)
            ->where('status', '<>', 'retired')
            ->update([
                'status' => 'active',
                'latest_version' => $version,
                'revision' => Db::raw('revision + 1'),
                'updated_by_member_id' => $memberId,
                'updated_at' => $now,
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
        $definition = $this->definition($tenantId, $moduleKey, $workflowKey, true)
            ?? throw WorkflowException::subjectNotFound();
        if ($definition->status === 'retired') {
            throw WorkflowException::definitionRetired();
        }
        if ($definition->status !== 'active') {
            throw WorkflowException::transitionUnavailable();
        }
        if ($definition->revision !== $expectedRevision) {
            throw WorkflowException::definitionConflict();
        }
        $updated = WorkflowDefinitionRecord::scope('tenant', $this->scope($tenantId))
            ->where('id', $definition->id)
            ->where('revision', $expectedRevision)
            ->where('status', 'active')
            ->update([
                'status' => 'retired',
                'revision' => Db::raw('revision + 1'),
                'updated_by_member_id' => $memberId,
                'updated_at' => $now,
                'retired_at' => $now,
            ]);
        if ($updated !== 1) {
            throw WorkflowException::definitionConflict();
        }

        return $this->definition($tenantId, $moduleKey, $workflowKey)
            ?? throw WorkflowException::internal();
    }

    public function definitionVersion(int $tenantId, int $definitionId, int $version): ?WorkflowDefinitionVersion
    {
        $record = WorkflowDefinitionVersionRecord::scope('tenant', $this->scope($tenantId))
            ->where('definition_id', $definitionId)
            ->where('version', $version)
            ->find();

        return $this->mapOne($record, WorkflowDefinitionVersion::fromRow(...));
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
            (new WorkflowInstanceRecord())->save([
                'instance_key' => $instanceKey,
                'tenant_id' => $tenantId,
                'definition_id' => $definitionId,
                'definition_version' => $definitionVersion,
                'subject_type' => $subjectType,
                'subject_key' => $subjectKey,
                'subject_revision_key' => $subjectRevisionKey,
                'subject_revision_sha256' => $subjectRevisionSha256,
                'current_node_key' => $currentNodeKey,
                'status' => 'active',
                'initiated_by_member_id' => $initiatedByMemberId,
                'last_actor_member_id' => $initiatedByMemberId,
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'completed_at' => null,
                'cancelled_at' => null,
            ]);
        } catch (PDOException $exception) {
            if ($this->duplicate($exception)) {
                throw WorkflowException::instanceConflict();
            }
            throw $exception;
        }

        return $this->instance($tenantId, $instanceKey) ?? throw WorkflowException::internal();
    }

    public function instance(int $tenantId, string $instanceKey, bool $forUpdate = false): ?WorkflowInstance
    {
        $query = WorkflowInstanceRecord::scope('tenant', $this->scope($tenantId))
            ->where('instance_key', $instanceKey);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $this->mapOne($query->find(), WorkflowInstance::fromRow(...));
    }

    /** @return list<WorkflowWorkItem> */
    public function pendingWorkItems(int $tenantId, int $instanceId, string $nodeKey, bool $forUpdate = false): array
    {
        $query = WorkflowWorkItemRecord::scope('tenant', $this->scope($tenantId))
            ->where('instance_id', $instanceId)
            ->where('node_key', $nodeKey)
            ->where('status', 'pending')
            ->order('id');
        if ($forUpdate) {
            $query->lock(true);
        }

        return $this->mapAll($query->select()->toArray(), WorkflowWorkItem::fromRow(...));
    }

    /**
     * @param list<array{work_item_key: string, source_kind: string, source_key: string, member_id: int}> $assignments
     * @return list<WorkflowWorkItem>
     */
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
            $record = new WorkflowWorkItemRecord();
            $record->save([
                'work_item_key' => $assignment['work_item_key'],
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
                'node_key' => $nodeKey,
                'round_no' => $roundNo,
                'assignment_source_kind' => $assignment['source_kind'],
                'assignment_source_key' => $assignment['source_key'],
                'assignee_member_id' => $assignment['member_id'],
                'status' => 'pending',
                'decision' => null,
                'completed_by_member_id' => null,
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'completed_at' => null,
                'cancelled_at' => null,
            ]);
            $items[] = WorkflowWorkItem::fromRow($record->toArray());
        }

        return $items;
    }

    public function nextRound(int $tenantId, int $instanceId, string $nodeKey): int
    {
        $round = WorkflowWorkItemRecord::scope('tenant', $this->scope($tenantId))
            ->where('instance_id', $instanceId)
            ->where('node_key', $nodeKey)
            ->max('round_no');

        return ((int) ($round ?? 0)) + 1;
    }

    public function completeWorkItem(int $tenantId, int $id, int $memberId, string $decision, string $now): void
    {
        $updated = WorkflowWorkItemRecord::scope('tenant', $this->scope($tenantId))
            ->where('id', $id)
            ->where('assignee_member_id', $memberId)
            ->where('status', 'pending')
            ->update([
                'status' => 'completed',
                'decision' => $decision,
                'completed_by_member_id' => $memberId,
                'revision' => Db::raw('revision + 1'),
                'updated_at' => $now,
                'completed_at' => $now,
            ]);
        if ($updated !== 1) {
            throw WorkflowException::assignmentDenied();
        }
    }

    public function cancelPendingWorkItems(int $tenantId, int $instanceId, string $nodeKey, ?int $exceptId, string $now): void
    {
        $query = WorkflowWorkItemRecord::scope('tenant', $this->scope($tenantId))
            ->where('instance_id', $instanceId)
            ->where('node_key', $nodeKey)
            ->where('status', 'pending');
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }
        $query->update([
            'status' => 'cancelled',
            'revision' => Db::raw('revision + 1'),
            'updated_at' => $now,
            'cancelled_at' => $now,
        ]);
    }

    /** @return list<string> */
    public function completedDecisions(int $tenantId, int $instanceId, string $nodeKey, int $roundNo): array
    {
        return array_values(array_map('strval', WorkflowWorkItemRecord::scope('tenant', $this->scope($tenantId))
            ->where('instance_id', $instanceId)
            ->where('node_key', $nodeKey)
            ->where('round_no', $roundNo)
            ->where('status', 'completed')
            ->order('id')
            ->column('decision')));
    }

    public function transitionInstance(
        WorkflowInstance $instance,
        string $toNodeKey,
        string $status,
        ?int $lastActorMemberId,
        string $now,
    ): WorkflowInstance {
        $updated = WorkflowInstanceRecord::scope('tenant', $this->scope($instance->tenantId))
            ->where('id', $instance->id)
            ->where('status', 'active')
            ->where('revision', $instance->revision)
            ->update([
                'current_node_key' => $toNodeKey,
                'status' => $status,
                'last_actor_member_id' => $lastActorMemberId,
                'revision' => Db::raw('revision + 1'),
                'updated_at' => $now,
                'completed_at' => $status === 'completed' ? $now : null,
                'cancelled_at' => $status === 'cancelled' ? $now : null,
            ]);
        if ($updated !== 1) {
            throw WorkflowException::instanceConflict();
        }

        return $this->instance($instance->tenantId, $instance->instanceKey)
            ?? throw WorkflowException::internal();
    }

    public function nextEventSequence(int $tenantId, int $instanceId): int
    {
        $sequence = WorkflowEventRecord::scope('tenant', $this->scope($tenantId))
            ->where('instance_id', $instanceId)
            ->max('sequence_no');

        return ((int) ($sequence ?? 0)) + 1;
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
        $record = new WorkflowEventRecord();
        $record->save([
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
            'comment_sha256' => $comment === null ? null : hash('sha256', $comment),
            'attachment_snapshots_json' => $attachmentSnapshotsJson,
            'metadata_json' => $metadataJson,
            'occurred_at' => $now,
        ]);

        return WorkflowEvent::fromRow($record->toArray());
    }

    public function transitionTraversalCount(int $tenantId, int $instanceId, string $transitionKey): int
    {
        return WorkflowEventRecord::scope('tenant', $this->scope($tenantId))
            ->where('instance_id', $instanceId)
            ->where('transition_key', $transitionKey)
            ->count();
    }

    /** @return list<WorkflowDefinition> */
    public function definitions(int $tenantId, ?string $status, int $page, int $pageSize): array
    {
        $query = WorkflowDefinitionRecord::scope('tenant', $this->scope($tenantId));
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $this->mapAll(
            $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray(),
            WorkflowDefinition::fromRow(...),
        );
    }

    /** @return list<WorkflowDefinitionVersion> */
    public function versions(int $tenantId, int $definitionId): array
    {
        return $this->mapAll(
            WorkflowDefinitionVersionRecord::scope('tenant', $this->scope($tenantId))
                ->where('definition_id', $definitionId)
                ->order('version')
                ->select()->toArray(),
            WorkflowDefinitionVersion::fromRow(...),
        );
    }

    /** @return list<WorkflowWorkItem> */
    public function workItems(int $tenantId, int $instanceId, ?string $status, int $page, int $pageSize): array
    {
        $query = WorkflowWorkItemRecord::scope('tenant', $this->scope($tenantId))
            ->where('instance_id', $instanceId);
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $this->mapAll(
            $query->order('id')->page($page, $pageSize)->select()->toArray(),
            WorkflowWorkItem::fromRow(...),
        );
    }

    /** @return list<WorkflowEvent> */
    public function events(int $tenantId, int $instanceId, int $afterSequence, int $pageSize): array
    {
        return $this->mapAll(
            WorkflowEventRecord::scope('tenant', $this->scope($tenantId))
                ->where('instance_id', $instanceId)
                ->where('sequence_no', '>', $afterSequence)
                ->order('id')
                ->limit($pageSize)
                ->select()->toArray(),
            WorkflowEvent::fromRow(...),
        );
    }

    private function scope(int $tenantId): TenantScope
    {
        return TenantScope::fromTrustedContext($tenantId, 'workflow');
    }

    /**
     * @template T
     * @param callable(array<string, mixed>): T $mapper
     * @return T|null
     */
    private function mapOne(?Model $record, callable $mapper): mixed
    {
        return $record === null ? null : $mapper($record->toArray());
    }

    /**
     * @template T
     * @param iterable<array<string, mixed>> $records
     * @param callable(array<string, mixed>): T $mapper
     * @return list<T>
     */
    private function mapAll(iterable $records, callable $mapper): array
    {
        $result = [];
        foreach ($records as $record) {
            $result[] = $mapper($record);
        }

        return $result;
    }

    private function duplicate(PDOException $exception): bool
    {
        $error = $exception->getData()['PDO Error Info'] ?? [];

        return (string) ($error['SQLSTATE'] ?? $exception->getCode()) === '23000'
            && (int) ($error['Driver Error Code'] ?? 0) === 1062;
    }
}
