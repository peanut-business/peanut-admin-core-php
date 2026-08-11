<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Workflow;

use PDO;
use PeanutAdmin\ArtifactRevision\Persistence\ArtifactRevisionRepository;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Workflow\Adapter\WorkflowSubjectRevisionResolver;
use PeanutAdmin\Workflow\Application\WorkflowException;
use Throwable;
use UnexpectedValueException;

final readonly class ArtifactWorkflowSubjectRevisionResolver implements WorkflowSubjectRevisionResolver
{
    public function __construct(private ArtifactRevisionRepository $repository) {}

    public function connection(): PDO
    {
        return $this->repository->connection();
    }

    public function resolve(
        AuthorizedOperationContext $context,
        string $subjectType,
        string $subjectKey,
        string $expectedRevisionKey,
    ): array {
        $this->assertContext($context, $subjectType, $subjectKey);
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $subjectType) !== 1
            || strlen($subjectType) > 64
            || preg_match('/^[\x21-\x7E]+$/D', $subjectKey) !== 1
            || strlen($subjectKey) > 128) {
            throw WorkflowException::subjectNotFound();
        }
        if (preg_match('/^revision_[0-9a-f]{32}$/D', $expectedRevisionKey) !== 1) {
            throw WorkflowException::subjectRevisionConflict();
        }

        try {
            $revision = $this->repository->revision(
                $context->tenantContext->tenantId,
                $subjectType,
                $subjectKey,
                $expectedRevisionKey,
            );
            if ($revision === null
                || !$revision->isFinalized()
                || $revision->canonicalEnvelopeSha256 === null
                || !hash_equals($revision->revisionKey, $expectedRevisionKey)) {
                throw WorkflowException::subjectRevisionConflict();
            }

            return [
                'revision_key' => $revision->revisionKey,
                'sha256' => $revision->canonicalEnvelopeSha256,
            ];
        } catch (WorkflowException $exception) {
            throw $exception;
        } catch (UnexpectedValueException) {
            throw WorkflowException::internal();
        } catch (Throwable) {
            throw WorkflowException::internal();
        }
    }

    private function assertContext(
        AuthorizedOperationContext $context,
        string $subjectType,
        string $subjectKey,
    ): void {
        $targets = array_values($context->targets);
        $target = $targets[0] ?? null;
        if ($context->tenantContext->tenantId < 1
            || $context->tenantContext->memberId < 1
            || $context->tenantContext->accountId < 1
            || $context->tenantContext->requestId === ''
            || $context->resourceKey === ''
            || $context->operation === ''
            || !hash_equals($context->resourceKey, $subjectType)
            || count($targets) !== 1
            || $target === null
            || $target->targetRole !== 'primary'
            || !hash_equals($target->targetResourceKey, $subjectType)
            || count($target->targetIds) !== 1
            || !hash_equals($target->targetIds[0], $subjectKey)) {
            throw WorkflowException::subjectNotFound();
        }
    }
}
