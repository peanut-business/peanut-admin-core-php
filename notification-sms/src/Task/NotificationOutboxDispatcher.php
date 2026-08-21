<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Task;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\NotificationSms\Application\NotificationException;
use PeanutAdmin\NotificationSms\Package;
use PeanutAdmin\NotificationSms\Persistence\NotificationRepository;
use PeanutAdmin\TaskJob\Application\JobRecord;
use PeanutAdmin\TaskJob\Submission\TrustedJobPublisher;

final readonly class NotificationOutboxDispatcher
{
    public function __construct(
        private NotificationRepository $repository,
        private TrustedJobPublisher $publisher,
    ) {}

    public function dispatch(AuthorizedOperationContext $context, string $outboxKey): JobRecord
    {
        if (!hash_equals(Package::RESOURCE_KEY, $context->resourceKey) || !hash_equals('manage', $context->operation)
            || preg_match('/^outbox_[0-9a-f]{32}$/D', $outboxKey) !== 1
        ) {
            throw NotificationException::denied();
        }
        return $this->repository->transaction(function () use ($context, $outboxKey): JobRecord {
            $outbox = $this->repository->outboxForSubmission($context->tenantContext->tenantId, $outboxKey);
            $taskType = 'notification.' . $outbox->channel . '.dispatch';
            $job = $this->publisher->publish($context, $taskType, ['outbox_key' => $outboxKey], $outboxKey);
            $this->repository->bindJob($context->tenantContext->tenantId, $outboxKey, $job->jobKey);
            return $job;
        });
    }
}
