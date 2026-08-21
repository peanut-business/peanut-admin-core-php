<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Persistence;

use PeanutAdmin\Workflow\Definition\WorkflowDefinition;
use PeanutAdmin\Workflow\Definition\WorkflowDefinitionVersion;
use PeanutAdmin\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Workflow\Instance\WorkflowEvent;
use PeanutAdmin\Workflow\Instance\WorkflowInstance;
use PeanutAdmin\Workflow\Instance\WorkflowWorkItem;

interface WorkflowRepository
{
    public function definition(int $tenantId, string $moduleKey, string $workflowKey, bool $forUpdate = false): ?WorkflowDefinition;

    public function saveDraft(
        int $tenantId,
        int $memberId,
        string $moduleKey,
        string $workflowKey,
        WorkflowGraph $graph,
        ?int $expectedRevision,
        string $now,
    ): WorkflowDefinition;

    /** @return array{definition: WorkflowDefinition, version: WorkflowDefinitionVersion} */
    public function publishDefinition(
        int $tenantId,
        int $memberId,
        string $moduleKey,
        string $workflowKey,
        int $expectedRevision,
        string $now,
    ): array;

    public function retireDefinition(
        int $tenantId,
        int $memberId,
        string $moduleKey,
        string $workflowKey,
        int $expectedRevision,
        string $now,
    ): WorkflowDefinition;

    public function definitionVersion(int $tenantId, int $definitionId, int $version): ?WorkflowDefinitionVersion;

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
    ): WorkflowInstance;

    public function instance(int $tenantId, string $instanceKey, bool $forUpdate = false): ?WorkflowInstance;

    /** @return list<WorkflowWorkItem> */
    public function pendingWorkItems(int $tenantId, int $instanceId, string $nodeKey, bool $forUpdate = false): array;

    /** @param list<array{work_item_key: string, source_kind: string, source_key: string, member_id: int}> $assignments
     * @return list<WorkflowWorkItem>
     */
    public function createWorkItems(
        int $tenantId,
        int $instanceId,
        string $nodeKey,
        int $roundNo,
        array $assignments,
        string $now,
    ): array;

    public function nextRound(int $tenantId, int $instanceId, string $nodeKey): int;

    public function completeWorkItem(int $tenantId, int $id, int $memberId, string $decision, string $now): void;

    public function cancelPendingWorkItems(int $tenantId, int $instanceId, string $nodeKey, ?int $exceptId, string $now): void;

    /** @return list<string> */
    public function completedDecisions(int $tenantId, int $instanceId, string $nodeKey, int $roundNo): array;

    public function transitionInstance(
        WorkflowInstance $instance,
        string $toNodeKey,
        string $status,
        ?int $lastActorMemberId,
        string $now,
    ): WorkflowInstance;

    public function nextEventSequence(int $tenantId, int $instanceId): int;

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
    ): WorkflowEvent;

    public function transitionTraversalCount(int $tenantId, int $instanceId, string $transitionKey): int;

    /** @return list<WorkflowDefinition> */
    public function definitions(int $tenantId, ?string $status, int $page, int $pageSize): array;

    /** @return list<WorkflowDefinitionVersion> */
    public function versions(int $tenantId, int $definitionId): array;

    /** @return list<WorkflowWorkItem> */
    public function workItems(int $tenantId, int $instanceId, ?string $status, int $page, int $pageSize): array;

    /** @return list<WorkflowEvent> */
    public function events(int $tenantId, int $instanceId, int $afterSequence, int $pageSize): array;
}
