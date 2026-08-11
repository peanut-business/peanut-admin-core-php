<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Application;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;
use PeanutAdmin\Kernel\Idempotency\PdoIdempotencyRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoAuditRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoTransactionManager;
use PeanutAdmin\Workflow\Adapter\WorkflowAssignmentResolver;
use PeanutAdmin\Workflow\Adapter\WorkflowAttachmentResolver;
use PeanutAdmin\Workflow\Adapter\WorkflowAuthorizationResolver;
use PeanutAdmin\Workflow\Adapter\WorkflowNotificationIntent;
use PeanutAdmin\Workflow\Adapter\WorkflowSideEffectPublisher;
use PeanutAdmin\Workflow\Adapter\WorkflowSubjectRevisionResolver;
use PeanutAdmin\Workflow\Adapter\WorkflowTaskIntent;
use PeanutAdmin\Workflow\Adapter\WorkflowTransitionEffects;
use PeanutAdmin\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Workflow\Definition\WorkflowNode;
use PeanutAdmin\Workflow\Definition\WorkflowTransition;
use PeanutAdmin\Workflow\Instance\WorkflowInstance;
use PeanutAdmin\Workflow\Instance\WorkflowWorkItem;
use PeanutAdmin\Workflow\Package;
use PeanutAdmin\Workflow\Persistence\PdoWorkflowRepository;
use Throwable;

final readonly class WorkflowRuntime
{
    private PdoWorkflowRepository $repository;
    private PdoTransactionManager $transactions;
    private PdoIdempotencyRepository $idempotency;
    private PdoAuditRepository $audit;

    public function __construct(
        private PDO $pdo,
        private WorkflowAssignmentResolver $assignments,
        private WorkflowAuthorizationResolver $authorization,
        private WorkflowSubjectRevisionResolver $subjects,
        private WorkflowAttachmentResolver $attachments,
        private WorkflowSideEffectPublisher $sideEffects,
    ) {
        try {
            foreach ([$assignments, $authorization, $subjects, $attachments, $sideEffects] as $adapter) {
                if ($adapter->connection() !== $pdo) {
                    throw WorkflowException::providerUnavailable();
                }
            }
        } catch (WorkflowException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw WorkflowException::internal();
        }
        $this->repository = new PdoWorkflowRepository($pdo);
        $this->transactions = new PdoTransactionManager($pdo);
        $this->idempotency = new PdoIdempotencyRepository($pdo);
        $this->audit = new PdoAuditRepository($pdo);
    }

    /** @param array<string, mixed> $graph */
    public function saveDraft(
        AuthorizedOperationContext $context,
        string $moduleKey,
        string $workflowKey,
        array $graph,
        ?int $expectedRevision,
        string $idempotencyKey,
    ): WorkflowReceipt {
        $this->assertDefinitionContext($context, 'write');
        $definitionGraph = WorkflowGraph::fromArray($graph);
        $this->assertIdentifier($moduleKey, 64);
        $this->assertIdentifier($workflowKey, 64);

        return $this->command(
            $context,
            'workflow.save-draft',
            [
                'module_key' => $moduleKey,
                'workflow_key' => $workflowKey,
                'graph_sha256' => $definitionGraph->sha256,
                'expected_revision' => $expectedRevision,
            ],
            $idempotencyKey,
            function (string $now) use ($context, $moduleKey, $workflowKey, $definitionGraph, $expectedRevision): WorkflowReceipt {
                $persisted = $this->repository->definition(
                    $context->tenantContext->tenantId,
                    $moduleKey,
                    $workflowKey,
                    true,
                );
                $persisted?->draftGraph();
                $definition = $this->repository->saveDraft(
                    $context->tenantContext->tenantId,
                    $context->tenantContext->memberId,
                    $moduleKey,
                    $workflowKey,
                    $definitionGraph,
                    $expectedRevision,
                    $now,
                );
                $this->audit->appendTenantMember(
                    $context->tenantContext,
                    'tenant.workflow.definition.draft_saved',
                    Package::DEFINITION_RESOURCE_KEY . '.write',
                    'workflow_definition',
                    (string) $definition->id,
                    targetCount: 1,
                    metadata: [
                        'revision' => $definition->revision,
                        'version' => $definition->latestVersion,
                        'graph_sha256' => $definition->draftGraphSha256,
                    ],
                );

                return new WorkflowReceipt(
                    'workflow.save-draft',
                    $definition->id,
                    $definition->latestVersion > 0 ? $definition->latestVersion : null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                );
            },
        );
    }

    public function publishDefinition(
        AuthorizedOperationContext $context,
        string $moduleKey,
        string $workflowKey,
        int $expectedRevision,
        string $idempotencyKey,
    ): WorkflowReceipt {
        $this->assertDefinitionContext($context, 'publish');
        $this->assertIdentifier($moduleKey, 64);
        $this->assertIdentifier($workflowKey, 64);
        $this->assertPositive($expectedRevision, true);

        return $this->command(
            $context,
            'workflow.publish-definition',
            ['module_key' => $moduleKey, 'workflow_key' => $workflowKey, 'expected_revision' => $expectedRevision],
            $idempotencyKey,
            function (string $now) use ($context, $moduleKey, $workflowKey, $expectedRevision): WorkflowReceipt {
                $persisted = $this->repository->definition(
                    $context->tenantContext->tenantId,
                    $moduleKey,
                    $workflowKey,
                    true,
                ) ?? throw WorkflowException::subjectNotFound();
                $persisted->draftGraph();
                $published = $this->repository->publishDefinition(
                    $context->tenantContext->tenantId,
                    $context->tenantContext->memberId,
                    $moduleKey,
                    $workflowKey,
                    $expectedRevision,
                    $now,
                );
                $definition = $published['definition'];
                $version = $published['version'];
                $version->graph();
                $this->audit->appendTenantMember(
                    $context->tenantContext,
                    'tenant.workflow.definition.published',
                    Package::DEFINITION_RESOURCE_KEY . '.publish',
                    'workflow_definition',
                    (string) $definition->id,
                    targetCount: 1,
                    metadata: [
                        'revision' => $definition->revision,
                        'version' => $version->version,
                        'graph_sha256' => $version->graphSha256,
                    ],
                );

                return new WorkflowReceipt(
                    'workflow.publish-definition',
                    $definition->id,
                    $version->version,
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                );
            },
        );
    }

    public function retireDefinition(
        AuthorizedOperationContext $context,
        string $moduleKey,
        string $workflowKey,
        int $expectedRevision,
        string $idempotencyKey,
    ): WorkflowReceipt {
        $this->assertDefinitionContext($context, 'publish');
        $this->assertIdentifier($moduleKey, 64);
        $this->assertIdentifier($workflowKey, 64);
        $this->assertPositive($expectedRevision, true);

        return $this->command(
            $context,
            'workflow.retire-definition',
            ['module_key' => $moduleKey, 'workflow_key' => $workflowKey, 'expected_revision' => $expectedRevision],
            $idempotencyKey,
            function (string $now) use ($context, $moduleKey, $workflowKey, $expectedRevision): WorkflowReceipt {
                $definition = $this->repository->retireDefinition(
                    $context->tenantContext->tenantId,
                    $context->tenantContext->memberId,
                    $moduleKey,
                    $workflowKey,
                    $expectedRevision,
                    $now,
                );
                $definition->draftGraph();
                $this->audit->appendTenantMember(
                    $context->tenantContext,
                    'tenant.workflow.definition.retired',
                    Package::DEFINITION_RESOURCE_KEY . '.publish',
                    'workflow_definition',
                    (string) $definition->id,
                    targetCount: 1,
                    metadata: [
                        'revision' => $definition->revision,
                        'version' => $definition->latestVersion,
                        'graph_sha256' => $definition->draftGraphSha256,
                    ],
                );

                return new WorkflowReceipt(
                    'workflow.retire-definition',
                    $definition->id,
                    $definition->latestVersion,
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                );
            },
        );
    }

    /** @param list<string> $attachmentFileKeys */
    public function startInstance(
        AuthorizedOperationContext $context,
        string $moduleKey,
        string $workflowKey,
        string $subjectType,
        string $subjectKey,
        string $subjectRevisionKey,
        array $attachmentFileKeys,
        string $idempotencyKey,
    ): WorkflowReceipt {
        $this->assertIdentifier($moduleKey, 64);
        $this->assertIdentifier($workflowKey, 64);
        $this->assertOpaqueIdentifier($subjectType);
        $this->assertOpaqueIdentifier($subjectKey);
        $this->assertOpaqueIdentifier($subjectRevisionKey);
        $attachmentFileKeys = $this->fileKeys($attachmentFileKeys);

        return $this->command(
            $context,
            'workflow.start-instance',
            [
                'module_key' => $moduleKey,
                'workflow_key' => $workflowKey,
                'subject_type' => $subjectType,
                'subject_key' => $subjectKey,
                'subject_revision_key' => $subjectRevisionKey,
                'attachment_file_keys' => $attachmentFileKeys,
            ],
            $idempotencyKey,
            function (string $now) use (
                $context,
                $moduleKey,
                $workflowKey,
                $subjectType,
                $subjectKey,
                $subjectRevisionKey,
                $attachmentFileKeys,
                $idempotencyKey,
            ): WorkflowReceipt {
                $definition = $this->repository->definition(
                    $context->tenantContext->tenantId,
                    $moduleKey,
                    $workflowKey,
                    true,
                );
                if ($definition === null) {
                    throw WorkflowException::subjectNotFound();
                }
                if ($definition->status === 'retired') {
                    throw WorkflowException::definitionRetired();
                }
                if ($definition->status !== 'active' || $definition->latestVersion < 1) {
                    throw WorkflowException::transitionUnavailable();
                }
                $version = $this->repository->definitionVersion(
                    $context->tenantContext->tenantId,
                    $definition->id,
                    $definition->latestVersion,
                ) ?? throw WorkflowException::internal();
                $graph = $version->graph();
                $authorized = $this->authorizeSubject(
                    $context,
                    $graph->subjectResourceKey,
                    $graph->subjectStartOperation,
                    [Package::INSTANCE_START_PERMISSION, ...$graph->startPermissionKeys],
                    $subjectKey,
                );
                $revision = $this->subjects->resolve(
                    $authorized,
                    $subjectType,
                    $subjectKey,
                    $subjectRevisionKey,
                );
                $this->assertSubjectRevision($revision, $subjectRevisionKey);
                $attachmentJson = $this->attachmentJson($authorized, $attachmentFileKeys);
                $start = $graph->startNode();
                $transition = $graph->outgoing($start->key)[0];
                $target = $graph->node($transition->to);
                $resolvedAssignments = $this->resolveAssignments(
                    $authorized,
                    $target,
                    $context->tenantContext->memberId,
                    $context->tenantContext->memberId,
                );
                $instance = $this->repository->createInstance(
                    $context->tenantContext->tenantId,
                    $definition->id,
                    $version->version,
                    'instance_' . bin2hex(random_bytes(16)),
                    $subjectType,
                    $subjectKey,
                    $revision['revision_key'],
                    $revision['sha256'],
                    $target->key,
                    $context->tenantContext->memberId,
                    $now,
                );
                $workItems = $this->createWorkItems($instance, $target, $resolvedAssignments, $now);
                $event = $this->repository->appendEvent(
                    $instance->tenantId,
                    $instance->id,
                    1,
                    'tenant.workflow.instance_started',
                    $transition->key,
                    $start->key,
                    $target->key,
                    'member',
                    $context->tenantContext->memberId,
                    $revision['revision_key'],
                    $revision['sha256'],
                    null,
                    $attachmentJson,
                    $this->canonicalJson(['action_kind' => $transition->actionKind]),
                    $now,
                );
                $this->publishEffects($authorized, $instance, $event->sequenceNo, $transition, $idempotencyKey);
                $this->audit->appendTenantMember(
                    $context->tenantContext,
                    'tenant.workflow.instance.started',
                    Package::INSTANCE_START_PERMISSION,
                    'workflow_instance',
                    $instance->instanceKey,
                    targetCount: 1,
                    metadata: $this->instanceAuditMetadata($instance, $start->key, $target->key, $transition->key),
                );

                return $this->receipt('workflow.start-instance', $instance, $event->sequenceNo, $workItems);
            },
        );
    }

    /** @param list<string> $attachmentFileKeys */
    public function applyTransition(
        AuthorizedOperationContext $context,
        string $instanceKey,
        string $transitionKey,
        int $expectedInstanceRevision,
        string $expectedSubjectRevisionKey,
        ?string $comment,
        array $attachmentFileKeys,
        string $idempotencyKey,
    ): WorkflowReceipt {
        $this->assertInstanceKey($instanceKey);
        $this->assertIdentifier($transitionKey, 64);
        $this->assertPositive($expectedInstanceRevision, false);
        $this->assertOpaqueIdentifier($expectedSubjectRevisionKey);
        $comment = $this->comment($comment);
        $attachmentFileKeys = $this->fileKeys($attachmentFileKeys);

        return $this->command(
            $context,
            'workflow.apply-transition',
            [
                'instance_key' => $instanceKey,
                'transition_key' => $transitionKey,
                'expected_instance_revision' => $expectedInstanceRevision,
                'expected_subject_revision_key' => $expectedSubjectRevisionKey,
                'comment' => $comment,
                'attachment_file_keys' => $attachmentFileKeys,
            ],
            $idempotencyKey,
            fn(string $now): WorkflowReceipt => $this->transition(
                $context,
                $instanceKey,
                $transitionKey,
                $expectedInstanceRevision,
                $expectedSubjectRevisionKey,
                $comment,
                $attachmentFileKeys,
                $idempotencyKey,
                null,
                $now,
            ),
        );
    }

    public function applyAutomation(
        AuthorizedOperationContext $context,
        string $instanceKey,
        string $transitionKey,
        int $expectedInstanceRevision,
        string $expectedSubjectRevisionKey,
        string $parentJobKey,
    ): WorkflowReceipt {
        $this->assertInstanceKey($instanceKey);
        $this->assertIdentifier($transitionKey, 64);
        $this->assertPositive($expectedInstanceRevision, false);
        $this->assertOpaqueIdentifier($expectedSubjectRevisionKey);
        if (preg_match('/^job_[0-9a-f]{32}$/D', $parentJobKey) !== 1) {
            throw WorkflowException::transitionUnavailable();
        }

        return $this->command(
            $context,
            'workflow.apply-automation',
            [
                'instance_key' => $instanceKey,
                'transition_key' => $transitionKey,
                'expected_instance_revision' => $expectedInstanceRevision,
                'expected_subject_revision_key' => $expectedSubjectRevisionKey,
                'parent_job_key' => $parentJobKey,
            ],
            $parentJobKey,
            fn(string $now): WorkflowReceipt => $this->transition(
                $context,
                $instanceKey,
                $transitionKey,
                $expectedInstanceRevision,
                $expectedSubjectRevisionKey,
                null,
                [],
                $parentJobKey,
                $parentJobKey,
                $now,
            ),
        );
    }

    /** @param list<string> $attachmentFileKeys */
    private function transition(
        AuthorizedOperationContext $context,
        string $instanceKey,
        string $transitionKey,
        int $expectedInstanceRevision,
        string $expectedSubjectRevisionKey,
        ?string $comment,
        array $attachmentFileKeys,
        string $parentIdempotencyKey,
        ?string $parentJobKey,
        string $now,
    ): WorkflowReceipt {
        $instance = $this->repository->instance($context->tenantContext->tenantId, $instanceKey, true);
        if ($instance === null) {
            throw WorkflowException::subjectNotFound();
        }
        if ($instance->status !== 'active') {
            throw WorkflowException::transitionUnavailable();
        }
        if ($instance->revision !== $expectedInstanceRevision) {
            throw WorkflowException::instanceConflict();
        }
        if (!hash_equals($instance->subjectRevisionKey, $expectedSubjectRevisionKey)) {
            throw WorkflowException::subjectRevisionConflict();
        }
        $version = $this->repository->definitionVersion(
            $instance->tenantId,
            $instance->definitionId,
            $instance->definitionVersion,
        ) ?? throw WorkflowException::internal();
        $graph = $version->graph();
        $transition = $graph->transition($transitionKey, $instance->currentNodeKey);
        if ($transition->returnEdge
            && $this->repository->transitionTraversalCount($instance->tenantId, $instance->id, $transition->key) >= $transition->maxTraversals) {
            throw WorkflowException::transitionUnavailable();
        }
        if ($parentJobKey !== null && ($transition->humanRequired || $transition->actionKind !== 'automate')) {
            throw WorkflowException::transitionUnavailable();
        }
        if ($parentJobKey === null && $transition->actionKind === 'automate') {
            throw WorkflowException::transitionUnavailable();
        }
        $permissions = $parentJobKey === null
            ? [Package::INSTANCE_TRANSITION_PERMISSION, ...$transition->permissionKeys]
            : $transition->permissionKeys;
        $authorized = $this->authorizeSubject(
            $context,
            $graph->subjectResourceKey,
            $transition->operation,
            $permissions,
            $instance->subjectKey,
        );
        $revision = $this->subjects->resolve(
            $authorized,
            $instance->subjectType,
            $instance->subjectKey,
            $expectedSubjectRevisionKey,
        );
        $this->assertSubjectRevision($revision, $expectedSubjectRevisionKey, $instance->subjectRevisionSha256);
        $attachmentJson = $this->attachmentJson($authorized, $attachmentFileKeys);
        $current = $graph->node($instance->currentNodeKey);
        $partial = false;
        if ($transition->humanRequired) {
            $partial = $this->completeHumanDecision($context, $instance, $current, $transition, $now);
        } elseif ($current->type === 'review') {
            $this->repository->cancelPendingWorkItems($instance->tenantId, $instance->id, $current->key, null, $now);
        }

        $sequence = $this->repository->nextEventSequence($instance->tenantId, $instance->id);
        if ($partial) {
            $event = $this->repository->appendEvent(
                $instance->tenantId,
                $instance->id,
                $sequence,
                'tenant.workflow.work_item_completed',
                null,
                $instance->currentNodeKey,
                $instance->currentNodeKey,
                'member',
                $context->tenantContext->memberId,
                $revision['revision_key'],
                $revision['sha256'],
                $comment,
                $attachmentJson,
                $this->canonicalJson(['decision' => $transition->key]),
                $now,
            );
            $this->auditTransition($context, $instance, $instance, $transition, $parentJobKey);

            return $this->receipt('workflow.apply-transition', $instance, $event->sequenceNo, []);
        }

        $target = $graph->node($transition->to);
        $resolvedAssignments = $this->resolveAssignments(
            $authorized,
            $target,
            $instance->initiatedByMemberId,
            $parentJobKey === null ? $context->tenantContext->memberId : $instance->lastActorMemberId,
        );
        $status = $target->type !== 'terminal'
            ? 'active'
            : ($transition->actionKind === 'withdraw' ? 'cancelled' : 'completed');
        $updated = $this->repository->transitionInstance(
            $instance,
            $target->key,
            $status,
            $parentJobKey === null ? $context->tenantContext->memberId : $instance->lastActorMemberId,
            $now,
        );
        $workItems = $this->createWorkItems($updated, $target, $resolvedAssignments, $now);
        $event = $this->repository->appendEvent(
            $updated->tenantId,
            $updated->id,
            $sequence,
            $parentJobKey === null ? 'tenant.workflow.instance_transitioned' : 'tenant.workflow.instance_automated',
            $transition->key,
            $instance->currentNodeKey,
            $target->key,
            $parentJobKey === null ? 'member' : 'tenant_system',
            $parentJobKey === null ? $context->tenantContext->memberId : null,
            $revision['revision_key'],
            $revision['sha256'],
            $comment,
            $attachmentJson,
            $this->canonicalJson(['action_kind' => $transition->actionKind]),
            $now,
        );
        $this->publishEffects($authorized, $updated, $event->sequenceNo, $transition, $parentIdempotencyKey);
        $this->auditTransition($context, $instance, $updated, $transition, $parentJobKey);

        return $this->receipt(
            $parentJobKey === null ? 'workflow.apply-transition' : 'workflow.apply-automation',
            $updated,
            $event->sequenceNo,
            $workItems,
        );
    }

    private function completeHumanDecision(
        AuthorizedOperationContext $context,
        WorkflowInstance $instance,
        WorkflowNode $node,
        WorkflowTransition $transition,
        string $now,
    ): bool {
        $pending = $this->repository->pendingWorkItems($instance->tenantId, $instance->id, $node->key, true);
        $actorItem = null;
        foreach ($pending as $item) {
            if ($item->assigneeMemberId === $context->tenantContext->memberId) {
                $actorItem = $item;
                break;
            }
        }
        if (!$actorItem instanceof WorkflowWorkItem) {
            throw WorkflowException::assignmentDenied();
        }
        if ($node->completionPolicy === 'all') {
            foreach ($this->repository->completedDecisions(
                $instance->tenantId,
                $instance->id,
                $node->key,
                $actorItem->roundNo,
            ) as $decision) {
                if (!hash_equals($decision, $transition->key)) {
                    throw WorkflowException::transitionUnavailable();
                }
            }
        }
        $this->repository->completeWorkItem(
            $instance->tenantId,
            $actorItem->id,
            $context->tenantContext->memberId,
            $transition->key,
            $now,
        );
        if ($node->completionPolicy === 'any') {
            $this->repository->cancelPendingWorkItems(
                $instance->tenantId,
                $instance->id,
                $node->key,
                $actorItem->id,
                $now,
            );

            return false;
        }

        return count($pending) > 1;
    }

    /** @param list<array{source_kind: string, source_key: string, member_id: int}> $assignments
     * @return list<WorkflowWorkItem>
     */
    private function createWorkItems(
        WorkflowInstance $instance,
        WorkflowNode $target,
        array $assignments,
        string $now,
    ): array {
        if ($target->type !== 'review') {
            return [];
        }
        $rows = [];
        foreach ($assignments as $assignment) {
            $rows[] = [
                'work_item_key' => 'work_' . bin2hex(random_bytes(16)),
                ...$assignment,
            ];
        }

        return $this->repository->createWorkItems(
            $instance->tenantId,
            $instance->id,
            $target->key,
            $this->repository->nextRound($instance->tenantId, $instance->id, $target->key),
            $rows,
            $now,
        );
    }

    /** @return list<array{source_kind: string, source_key: string, member_id: int}> */
    private function resolveAssignments(
        AuthorizedOperationContext $context,
        WorkflowNode $target,
        int $initiatorMemberId,
        ?int $previousActorMemberId,
    ): array {
        if ($target->type !== 'review') {
            return [];
        }
        $resolved = $this->assignments->resolve(
            $context,
            $target->assignments,
            $initiatorMemberId,
            $previousActorMemberId,
        );
        $declared = [];
        foreach ($target->assignments as $rule) {
            $declared[$rule['kind'] . ':' . ($rule['key'] ?? '')] = true;
        }
        $normalized = [];
        foreach ($resolved as $assignment) {
            $keys = is_array($assignment) ? array_keys($assignment) : [];
            sort($keys, SORT_STRING);
            if (!is_array($assignment)
                || $keys !== ['member_id', 'source_key', 'source_kind']
                || !is_string($assignment['source_kind'])
                || !in_array($assignment['source_kind'], ['member', 'role', 'department', 'initiator', 'previous_actor'], true)
                || !is_string($assignment['source_key'])
                || $assignment['source_key'] === ''
                || strlen($assignment['source_key']) > 160
                || preg_match('/^[\x21-\x7e]+$/D', $assignment['source_key']) !== 1
                || !is_int($assignment['member_id'])
                || $assignment['member_id'] < 1
                || !$this->assignmentMatchesRule(
                    $assignment['source_kind'],
                    $assignment['source_key'],
                    $assignment['member_id'],
                    $initiatorMemberId,
                    $previousActorMemberId,
                    $declared,
                )
            ) {
                throw WorkflowException::assignmentDenied();
            }
            $normalized[$assignment['member_id']] ??= $assignment;
        }
        if ($normalized === []) {
            throw WorkflowException::assignmentDenied();
        }
        ksort($normalized, SORT_NUMERIC);

        return array_values($normalized);
    }

    /** @param list<string> $permissionKeys */
    private function authorizeSubject(
        AuthorizedOperationContext $basis,
        string $resourceKey,
        string $operation,
        array $permissionKeys,
        string $subjectKey,
    ): AuthorizedOperationContext {
        $permissionKeys = array_values(array_unique($permissionKeys, SORT_STRING));
        sort($permissionKeys, SORT_STRING);
        $authorized = $this->authorization->authorize(
            $basis,
            $resourceKey,
            $operation,
            $permissionKeys,
            $subjectKey,
        );
        if ($authorized->tenantContext !== $basis->tenantContext
            || !hash_equals($authorized->resourceKey, $resourceKey)
            || !hash_equals($authorized->operation, $operation)
            || count($authorized->targets) !== 1
        ) {
            throw WorkflowException::subjectNotFound();
        }
        $target = $authorized->targets[0];
        if (!$target instanceof RequestedTargetSet
            || !hash_equals($target->targetResourceKey, $resourceKey)
            || !hash_equals($target->targetRole, 'primary')
            || $target->targetIds !== [$subjectKey]
        ) {
            throw WorkflowException::subjectNotFound();
        }

        return $authorized;
    }

    /** @param list<string> $fileKeys */
    private function attachmentJson(AuthorizedOperationContext $context, array $fileKeys): string
    {
        $snapshots = [];
        foreach ($fileKeys as $fileKey) {
            $attachment = $this->attachments->snapshot($context, $fileKey);
            if (!hash_equals($attachment->fileKey, $fileKey)) {
                throw WorkflowException::attachmentUnavailable();
            }
            $snapshots[] = $attachment->toArray();
        }
        usort($snapshots, static fn(array $left, array $right): int => strcmp($left['file_key'], $right['file_key']));

        return $this->canonicalJson($snapshots);
    }

    private function publishEffects(
        AuthorizedOperationContext $context,
        WorkflowInstance $instance,
        int $sequence,
        WorkflowTransition $transition,
        string $parentIdempotencyKey,
    ): void {
        $notifications = $transition->notificationIntent === null ? [] : [new WorkflowNotificationIntent(
            $transition->notificationIntent['template_key'],
            $transition->notificationIntent['recipient_rule'],
        )];
        $tasks = $transition->taskIntent === null ? [] : [new WorkflowTaskIntent(
            $transition->taskIntent['task_type'],
        )];
        if ($notifications === [] && $tasks === []) {
            return;
        }
        $effects = new WorkflowTransitionEffects(
            $instance->instanceKey,
            $sequence,
            $transition->key,
            $instance->subjectRevisionKey,
            $notifications,
            $tasks,
        );
        $this->sideEffects->publish($this->pdo, $context, $effects, $parentIdempotencyKey);
    }

    private function auditTransition(
        AuthorizedOperationContext $context,
        WorkflowInstance $before,
        WorkflowInstance $after,
        WorkflowTransition $transition,
        ?string $parentJobKey,
    ): void {
        $metadata = $this->instanceAuditMetadata($before, $before->currentNodeKey, $after->currentNodeKey, $transition->key);
        if ($parentJobKey === null) {
            $this->audit->appendTenantMember(
                $context->tenantContext,
                'tenant.workflow.instance.transitioned',
                Package::INSTANCE_TRANSITION_PERMISSION,
                'workflow_instance',
                $before->instanceKey,
                targetCount: 1,
                metadata: $metadata,
            );
            return;
        }
        $metadata['parent_job_key_sha256'] = hash('sha256', $parentJobKey);
        $this->audit->appendTenantSystem(
            $before->tenantId,
            'tenant.workflow.instance.automated',
            $transition->operation,
            $context->tenantContext->requestId,
            $metadata,
        );
    }

    /** @return array<string, bool|int|string|null> */
    private function instanceAuditMetadata(
        WorkflowInstance $instance,
        string $from,
        string $to,
        string $transition,
    ): array {
        return [
            'definition_id' => $instance->definitionId,
            'definition_version' => $instance->definitionVersion,
            'from_node_key' => $from,
            'to_node_key' => $to,
            'transition_key' => $transition,
            'subject_revision_digest' => hash(
                'sha256',
                $instance->subjectType . '|' . $instance->subjectKey . '|' . $instance->subjectRevisionKey,
            ),
        ];
    }

    /** @param list<WorkflowWorkItem> $workItems */
    private function receipt(string $operation, WorkflowInstance $instance, int $eventSequence, array $workItems): WorkflowReceipt
    {
        return new WorkflowReceipt(
            $operation,
            $instance->definitionId,
            $instance->definitionVersion,
            $instance->instanceKey,
            $instance->status,
            $instance->currentNodeKey,
            $instance->revision,
            $eventSequence,
            array_map(static fn(WorkflowWorkItem $item): string => $item->workItemKey, $workItems),
        );
    }

    /** @template T of WorkflowReceipt
     * @param array<string, mixed> $semanticInputs
     * @param callable(string): T $operation
     * @return T
     */
    private function command(
        AuthorizedOperationContext $context,
        string $operationId,
        array $semanticInputs,
        string $rawIdempotencyKey,
        callable $operation,
    ): WorkflowReceipt {
        try {
            $this->assertTrustedTenantContext($context);
            $key = IdempotencyKey::fromString($rawIdempotencyKey);
            $requestHash = hash('sha256', $this->canonicalJson($semanticInputs));
            $comparison = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $expires = $comparison->modify('+1 day');

            return $this->transactions->run(function () use (
                $context,
                $operationId,
                $key,
                $requestHash,
                $comparison,
                $expires,
                $operation,
            ): WorkflowReceipt {
                $record = $this->idempotency->beginTenant(
                    $context->tenantContext->tenantId,
                    $context->tenantContext->memberId,
                    $operationId,
                    $key,
                    $requestHash,
                    $expires,
                    $comparison,
                );
                if ($record->replayable()) {
                    return WorkflowReceipt::fromArray((array) $record->responseBody, $operationId);
                }
                if (!$record->acquiredForExecution()) {
                    throw new ApiException(
                        'IDEMPOTENCY_REQUEST_PROCESSING',
                        409,
                        'Another workflow request with this Idempotency-Key is processing.',
                    );
                }
                $receipt = $operation($comparison->format('Y-m-d H:i:s.v'));
                $this->idempotency->completeTenant(
                    $record->id,
                    200,
                    $receipt->toArray(),
                    $receipt->instanceKey === null ? 'workflow_definition' : 'workflow_instance',
                    $receipt->instanceKey ?? ($receipt->definitionId === null ? null : (string) $receipt->definitionId),
                );

                return $receipt;
            });
        } catch (WorkflowException|ApiException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw WorkflowException::internal();
        }
    }

    /** @param array<string, true> $declared */
    private function assignmentMatchesRule(
        string $kind,
        string $sourceKey,
        int $memberId,
        int $initiatorMemberId,
        ?int $previousActorMemberId,
        array $declared,
    ): bool {
        if ($kind === 'member') {
            return isset($declared['member:' . $sourceKey])
                && preg_match('/^[1-9][0-9]*$/D', $sourceKey) === 1
                && (string) $memberId === $sourceKey;
        }
        if ($kind === 'role' || $kind === 'department') {
            return isset($declared[$kind . ':' . $sourceKey])
                && preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $sourceKey) === 1;
        }
        if ($kind === 'initiator') {
            return isset($declared['initiator:'])
                && $sourceKey === (string) $initiatorMemberId
                && $memberId === $initiatorMemberId;
        }

        return $kind === 'previous_actor'
            && isset($declared['previous_actor:'])
            && $previousActorMemberId !== null
            && $sourceKey === (string) $previousActorMemberId
            && $memberId === $previousActorMemberId;
    }

    private function assertDefinitionContext(AuthorizedOperationContext $context, string $operation): void
    {
        $this->assertTrustedTenantContext($context);
        if (!hash_equals($context->resourceKey, Package::DEFINITION_RESOURCE_KEY)
            || !hash_equals($context->operation, $operation)
            || $context->targets !== []) {
            throw WorkflowException::subjectNotFound();
        }
    }

    private function assertTrustedTenantContext(AuthorizedOperationContext $context): void
    {
        if ($context->tenantContext->tenantId < 1
            || $context->tenantContext->memberId < 1
            || $context->tenantContext->accountId < 1
            || $context->tenantContext->requestId === '') {
            throw WorkflowException::subjectNotFound();
        }
    }

    /** @param array{revision_key: string, sha256: string} $revision */
    private function assertSubjectRevision(array $revision, string $expectedKey, ?string $expectedSha256 = null): void
    {
        $keys = array_keys($revision);
        sort($keys, SORT_STRING);
        if ($keys !== ['revision_key', 'sha256']
            || !is_string($revision['revision_key'])
            || !is_string($revision['sha256'])
            || !hash_equals($revision['revision_key'], $expectedKey)
            || preg_match('/^[0-9a-f]{64}$/D', $revision['sha256']) !== 1
            || ($expectedSha256 !== null && !hash_equals($revision['sha256'], $expectedSha256))) {
            throw WorkflowException::subjectRevisionConflict();
        }
    }

    /** @param list<string> $fileKeys @return list<string> */
    private function fileKeys(array $fileKeys): array
    {
        $normalized = [];
        foreach ($fileKeys as $fileKey) {
            if (!is_string($fileKey) || preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) !== 1 || isset($normalized[$fileKey])) {
                throw WorkflowException::attachmentUnavailable();
            }
            $normalized[$fileKey] = true;
        }
        $keys = array_keys($normalized);
        sort($keys, SORT_STRING);

        return $keys;
    }

    private function comment(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }
        $comment = trim($comment);
        $characters = preg_match_all('/./us', $comment);
        if ($comment === '' || $characters === false || $characters > 2000 || str_contains($comment, "\0")) {
            throw WorkflowException::definitionInvalid('Workflow comment is invalid.');
        }

        return $comment;
    }

    private function assertIdentifier(string $value, int $maximum): void
    {
        if (strlen($value) < 1
            || strlen($value) > $maximum
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $value) !== 1) {
            throw WorkflowException::definitionInvalid();
        }
    }

    private function assertOpaqueIdentifier(string $value): void
    {
        if ($value === '' || strlen($value) > 160 || preg_match('/^[\x21-\x7e]+$/D', $value) !== 1) {
            throw WorkflowException::definitionInvalid();
        }
    }

    private function assertInstanceKey(string $instanceKey): void
    {
        if (preg_match('/^instance_[0-9a-f]{32}$/D', $instanceKey) !== 1) {
            throw WorkflowException::subjectNotFound();
        }
    }

    private function assertPositive(int $value, bool $definition): void
    {
        if ($value < 1) {
            throw $definition ? WorkflowException::preconditionRequired() : WorkflowException::instanceConflict();
        }
    }

    private function canonicalJson(mixed $value): string
    {
        try {
            return json_encode(
                $this->normalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw WorkflowException::internal();
        }
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
