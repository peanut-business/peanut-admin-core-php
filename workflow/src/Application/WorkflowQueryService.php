<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Application;

use PDO;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Workflow\Adapter\WorkflowAuthorizationResolver;
use PeanutAdmin\Workflow\Definition\WorkflowDefinition;
use PeanutAdmin\Workflow\Definition\WorkflowDefinitionVersion;
use PeanutAdmin\Workflow\Instance\WorkflowEvent;
use PeanutAdmin\Workflow\Instance\WorkflowInstance;
use PeanutAdmin\Workflow\Package;
use PeanutAdmin\Workflow\Persistence\PdoWorkflowRepository;
use Throwable;

final readonly class WorkflowQueryService
{
    private PdoWorkflowRepository $repository;

    public function __construct(
        PDO $pdo,
        private WorkflowAuthorizationResolver $authorization,
    ) {
        try {
            if ($authorization->connection() !== $pdo) {
                throw WorkflowException::providerUnavailable();
            }
        } catch (WorkflowException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw WorkflowException::internal();
        }
        $this->repository = new PdoWorkflowRepository($pdo);
    }

    /** @return array<string, mixed> */
    public function definition(
        AuthorizedOperationContext $context,
        string $moduleKey,
        string $workflowKey,
    ): array {
        return $this->guard(function () use ($context, $moduleKey, $workflowKey): array {
            $this->assertDefinitionContext($context, 'read');
            $this->assertIdentifier($moduleKey);
            $this->assertIdentifier($workflowKey);
            $definition = $this->repository->definition(
                $context->tenantContext->tenantId,
                $moduleKey,
                $workflowKey,
            ) ?? throw WorkflowException::subjectNotFound();

            return $this->definitionSummary($definition, true);
        });
    }

    /** @return array<string, mixed> */
    public function definitionDraft(
        AuthorizedOperationContext $writeContext,
        string $moduleKey,
        string $workflowKey,
    ): array {
        return $this->guard(function () use ($writeContext, $moduleKey, $workflowKey): array {
            $this->assertDefinitionContext($writeContext, 'write');
            $this->assertIdentifier($moduleKey);
            $this->assertIdentifier($workflowKey);
            $definition = $this->repository->definition(
                $writeContext->tenantContext->tenantId,
                $moduleKey,
                $workflowKey,
            ) ?? throw WorkflowException::subjectNotFound();
            $graph = json_decode($definition->draftGraph()->canonicalJson, true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($graph) || array_is_list($graph)) {
                throw WorkflowException::internal();
            }
            $summary = $this->definitionSummary($definition, true);
            $summary['draft_graph'] = $graph;

            return $summary;
        });
    }

    /** @return list<array<string, mixed>> */
    public function definitions(
        AuthorizedOperationContext $context,
        ?string $status,
        int $page,
        int $pageSize,
    ): array {
        return $this->guard(function () use ($context, $status, $page, $pageSize): array {
            $this->assertDefinitionContext($context, 'read');
            $this->assertPage($page, $pageSize);
            if ($status === 'all') {
                $status = null;
            }
            if ($status !== null && !in_array($status, ['draft', 'active', 'retired'], true)) {
                throw WorkflowException::definitionInvalid();
            }

            return array_map(
                fn(WorkflowDefinition $definition): array => $this->definitionSummary($definition, false),
                $this->repository->definitions($context->tenantContext->tenantId, $status, $page, $pageSize),
            );
        });
    }

    /** @return array<string, int|string|null> */
    public function instance(AuthorizedOperationContext $context, string $instanceKey): array
    {
        return $this->guard(fn(): array => $this->instanceSummary($this->visibleInstance($context, $instanceKey)));
    }

    /** @return list<array<string, int|string|null>> */
    public function workItems(
        AuthorizedOperationContext $context,
        string $instanceKey,
        ?string $status,
        int $page,
        int $pageSize,
    ): array {
        return $this->guard(function () use ($context, $instanceKey, $status, $page, $pageSize): array {
            $this->assertPage($page, $pageSize);
            if ($status === 'all') {
                $status = null;
            }
            if ($status !== null && !in_array($status, ['pending', 'completed', 'cancelled'], true)) {
                throw WorkflowException::definitionInvalid();
            }
            $instance = $this->visibleInstance($context, $instanceKey);

            return array_map(
                static fn($item): array => $item->toArray(),
                $this->repository->workItems($instance->tenantId, $instance->id, $status, $page, $pageSize),
            );
        });
    }

    /** @return list<array<string, mixed>> */
    public function events(
        AuthorizedOperationContext $context,
        string $instanceKey,
        int $afterSequence,
        int $pageSize,
    ): array {
        return $this->guard(function () use ($context, $instanceKey, $afterSequence, $pageSize): array {
            if ($afterSequence < 0 || $pageSize < 1 || $pageSize > 100) {
                throw WorkflowException::definitionInvalid();
            }
            $instance = $this->visibleInstance($context, $instanceKey);

            return array_map(
                fn(WorkflowEvent $event): array => $this->eventSummary($event),
                $this->repository->events($instance->tenantId, $instance->id, $afterSequence, $pageSize),
            );
        });
    }

    /** @template T @param callable(): T $query @return T */
    private function guard(callable $query): mixed
    {
        try {
            return $query();
        } catch (WorkflowException|ApiException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw WorkflowException::internal();
        }
    }

    private function visibleInstance(AuthorizedOperationContext $basis, string $instanceKey): WorkflowInstance
    {
        if (preg_match('/^instance_[0-9a-f]{32}$/D', $instanceKey) !== 1) {
            throw WorkflowException::subjectNotFound();
        }
        $instance = $this->repository->instance($basis->tenantContext->tenantId, $instanceKey)
            ?? throw WorkflowException::subjectNotFound();
        $version = $this->repository->definitionVersion(
            $instance->tenantId,
            $instance->definitionId,
            $instance->definitionVersion,
        ) ?? throw WorkflowException::internal();
        $graph = $version->graph();
        try {
            $authorized = $this->authorization->authorize(
                $basis,
                $graph->subjectResourceKey,
                $graph->subjectReadOperation,
                [Package::INSTANCE_READ_PERMISSION],
                $instance->subjectKey,
            );
        } catch (ApiException $exception) {
            if (in_array($exception->httpStatus, [403, 404], true)) {
                throw WorkflowException::subjectNotFound();
            }
            throw WorkflowException::internal();
        } catch (Throwable) {
            throw WorkflowException::internal();
        }
        if ($authorized->tenantContext !== $basis->tenantContext
            || !hash_equals($authorized->resourceKey, $graph->subjectResourceKey)
            || !hash_equals($authorized->operation, $graph->subjectReadOperation)
            || count($authorized->targets) !== 1
        ) {
            throw WorkflowException::subjectNotFound();
        }
        $target = $authorized->targets[0];
        if (!$target instanceof RequestedTargetSet
            || !hash_equals($target->targetResourceKey, $graph->subjectResourceKey)
            || !hash_equals($target->targetRole, 'primary')
            || $target->targetIds !== [$instance->subjectKey]) {
            throw WorkflowException::subjectNotFound();
        }

        return $instance;
    }

    /** @return array<string, mixed> */
    private function definitionSummary(WorkflowDefinition $definition, bool $withVersions): array
    {
        $definition->draftGraph();
        $summary = [
            'definition_id' => $definition->id,
            'module_key' => $definition->moduleKey,
            'workflow_key' => $definition->workflowKey,
            'status' => $definition->status,
            'draft_revision' => $definition->revision,
            'draft_graph_sha256' => $definition->draftGraphSha256,
            'latest_version' => $definition->latestVersion,
        ];
        if ($withVersions) {
            $summary['versions'] = array_map(
                static function (WorkflowDefinitionVersion $version): array {
                    $version->graph();

                    return [
                        'version' => $version->version,
                        'graph_sha256' => $version->graphSha256,
                        'published_by_member_id' => $version->publishedByMemberId,
                        'published_at' => $version->publishedAt,
                    ];
                },
                $this->repository->versions($definition->tenantId, $definition->id),
            );
        }

        return $summary;
    }

    /** @return array<string, int|string> */
    private function instanceSummary(WorkflowInstance $instance): array
    {
        return [
            'instance_key' => $instance->instanceKey,
            'definition_id' => $instance->definitionId,
            'definition_version' => $instance->definitionVersion,
            'subject_type' => $instance->subjectType,
            'subject_key' => $instance->subjectKey,
            'subject_revision_key' => $instance->subjectRevisionKey,
            'subject_revision_sha256' => $instance->subjectRevisionSha256,
            'current_node_key' => $instance->currentNodeKey,
            'status' => $instance->status,
            'revision' => $instance->revision,
        ];
    }

    /** @return array<string, mixed> */
    private function eventSummary(WorkflowEvent $event): array
    {
        $summary = $event->toArray();
        $attachments = $summary['attachment_snapshots'] ?? null;
        $metadata = $summary['metadata'] ?? null;
        if (!is_array($attachments)
            || !array_is_list($attachments)
            || !is_array($metadata)
            || ($metadata !== [] && array_is_list($metadata))) {
            throw WorkflowException::internal();
        }

        return $summary;
    }

    private function assertDefinitionContext(AuthorizedOperationContext $context, string $operation): void
    {
        if ($context->tenantContext->tenantId < 1
            || $context->tenantContext->memberId < 1
            || !hash_equals($context->resourceKey, Package::DEFINITION_RESOURCE_KEY)
            || !hash_equals($context->operation, $operation)
            || $context->targets !== []) {
            throw WorkflowException::subjectNotFound();
        }
    }

    private function assertIdentifier(string $value): void
    {
        if (strlen($value) < 1
            || strlen($value) > 64
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $value) !== 1) {
            throw WorkflowException::definitionInvalid();
        }
    }

    private function assertPage(int $page, int $pageSize): void
    {
        if ($page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw WorkflowException::definitionInvalid();
        }
    }
}
