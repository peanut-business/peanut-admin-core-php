<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Application;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\TaskJob\Persistence\PdoTaskJobRepository;

final readonly class TaskJobService
{
    public const RESOURCE_KEY = 'peanut.task-job';

    public function __construct(
        private PdoTaskJobRepository $repository,
    ) {}

    /** @return array{items: list<JobRecord>, page: int, page_size: int, total: int} */
    public function list(AuthorizedOperationContext $context, string $status, int $page, int $pageSize): array
    {
        $this->assertOperation($context, 'read');
        $this->assertStatus($status);
        if ($page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw TaskJobException::invalid();
        }
        return $this->repository->list($context->tenantContext->tenantId, $status, $page, $pageSize);
    }

    public function detail(AuthorizedOperationContext $context, string $jobKey): JobRecord
    {
        $this->assertOperation($context, 'read');
        $this->assertJobKey($jobKey);
        return $this->repository->get($context->tenantContext->tenantId, $jobKey);
    }

    public function cancel(AuthorizedOperationContext $context, string $jobKey, int $revision): JobRecord
    {
        $this->assertOperation($context, 'manage');
        $this->assertJobKey($jobKey);
        if ($revision < 1) {
            throw TaskJobException::invalid();
        }
        return $this->repository->cancel($context->tenantContext->tenantId, $context->tenantContext->memberId, $jobKey, $revision);
    }

    public function retry(AuthorizedOperationContext $context, string $jobKey, int $revision): JobRecord
    {
        $this->assertOperation($context, 'manage');
        $this->assertJobKey($jobKey);
        if ($revision < 1) {
            throw TaskJobException::invalid();
        }
        return $this->repository->retryDead($context->tenantContext->tenantId, $context->tenantContext->memberId, $jobKey, $revision);
    }

    private function assertOperation(AuthorizedOperationContext $context, string $operation): void
    {
        if (!hash_equals(self::RESOURCE_KEY, $context->resourceKey) || !hash_equals($operation, $context->operation)) {
            throw TaskJobException::denied();
        }
    }

    private function assertStatus(string $status): void
    {
        if (!in_array($status, ['queued', 'running', 'succeeded', 'dead', 'cancelled'], true)) {
            throw TaskJobException::invalid();
        }
    }

    private function assertJobKey(string $jobKey): void
    {
        if (preg_match('/^job_[0-9a-f]{32}$/D', $jobKey) !== 1) {
            throw TaskJobException::notFound();
        }
    }

}
