<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Task;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\NotificationSms\Application\NotificationException;
use PeanutAdmin\NotificationSms\Package;
use PeanutAdmin\NotificationSms\Persistence\NotificationRepository;
use PeanutAdmin\NotificationSms\Persistence\SmsDispatch;
use PeanutAdmin\NotificationSms\Sms\SmsProvider;
use PeanutAdmin\NotificationSms\Sms\SmsProviderException;
use PeanutAdmin\NotificationSms\Sms\SmsRecipientResolver;
use PeanutAdmin\NotificationSms\Sms\SmsSendRequest;
use PeanutAdmin\TaskJob\Execution\JobExecution;
use PeanutAdmin\TaskJob\Execution\RetryableTaskException;
use PeanutAdmin\TaskJob\Execution\TaskHandler;
use Throwable;

final readonly class SmsTaskHandler implements TaskHandler
{
    public function __construct(
        private NotificationRepository $repository,
        private SmsRecipientResolver $recipients,
        private SmsProvider $provider,
    ) {}

    public function key(): string
    {
        return 'notification.sms';
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        if ($context->tenantContext->tenantId !== $execution->tenantId
            || !hash_equals(Package::RESOURCE_KEY, $context->resourceKey)
            || !hash_equals('manage', $context->operation)
        ) {
            throw NotificationException::denied();
        }
        $outboxKey = $this->outboxKey($execution);
        try {
            $dispatch = $this->repository->beginSms($execution->tenantId, $outboxKey, $execution->jobKey);
        } catch (NotificationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RetryableTaskException('SMS_OUTBOX_PERSISTENCE_FAILED');
        }
        if ($dispatch->alreadyDelivered) {
            return;
        }
        try {
            $recipient = $this->recipients->resolve($dispatch->tenantId, $dispatch->recipientMemberId);
        } catch (NotificationException) {
            $this->recordFailure($dispatch, 'SMS_RECIPIENT_UNAVAILABLE', false);
            throw NotificationException::invalid('SMS_RECIPIENT_UNAVAILABLE');
        } catch (Throwable) {
            $this->recordFailure($dispatch, 'SMS_RECIPIENT_LOOKUP_FAILED', true);
            throw new RetryableTaskException('SMS_RECIPIENT_LOOKUP_FAILED');
        }
        if (!hash_equals($dispatch->recipientDigest(), $recipient->digest)) {
            $this->recordFailure($dispatch, 'SMS_RECIPIENT_CHANGED', false);
            throw NotificationException::invalid('SMS_RECIPIENT_CHANGED');
        }
        try {
            $rateAllowed = $this->repository->reserveSmsRate($dispatch->tenantId, $dispatch->recipientDigest());
        } catch (Throwable) {
            $this->recordFailure($dispatch, 'SMS_RATE_CHECK_FAILED', true);
            throw new RetryableTaskException('SMS_RATE_CHECK_FAILED');
        }
        if (!$rateAllowed) {
            $this->recordFailure($dispatch, 'SMS_RATE_LIMITED', true);
            throw new RetryableTaskException('SMS_RATE_LIMITED');
        }
        try {
            $receipt = $this->provider->send(new SmsSendRequest(
                $execution->jobKey,
                $dispatch->tenantId,
                $dispatch->outboxKey,
                $recipient->number(),
                $dispatch->messageBody(),
            ));
            if (!hash_equals($this->provider->key(), $receipt->providerKey)) {
                throw SmsProviderException::permanent('SMS_PROVIDER_RECEIPT_INVALID');
            }
        } catch (SmsProviderException $exception) {
            $this->recordFailure($dispatch, $exception->safeCode, $exception->retryable);
            if ($exception->retryable) {
                throw new RetryableTaskException($exception->safeCode);
            }
            throw NotificationException::invalid($exception->safeCode);
        } catch (NotificationException) {
            $this->recordFailure($dispatch, 'SMS_PROVIDER_RECEIPT_INVALID', false);
            throw NotificationException::invalid('SMS_PROVIDER_RECEIPT_INVALID');
        } catch (Throwable) {
            $this->recordFailure($dispatch, 'SMS_PROVIDER_UNAVAILABLE', true);
            throw new RetryableTaskException('SMS_PROVIDER_UNAVAILABLE');
        }
        try {
            $this->repository->completeSms($dispatch, $receipt);
        } catch (Throwable) {
            $this->recordFailure($dispatch, 'SMS_DELIVERY_COMMIT_FAILED', true);
            throw new RetryableTaskException('SMS_DELIVERY_COMMIT_FAILED');
        }
    }

    private function outboxKey(JobExecution $execution): string
    {
        if (array_keys($execution->payload) !== ['outbox_key'] || !is_string($execution->payload['outbox_key'])
            || preg_match('/^outbox_[0-9a-f]{32}$/D', $execution->payload['outbox_key']) !== 1
        ) {
            throw NotificationException::invalid();
        }
        return $execution->payload['outbox_key'];
    }

    private function recordFailure(SmsDispatch $dispatch, string $safeCode, bool $retryable): void
    {
        try {
            $this->repository->failSms($dispatch, $safeCode, $retryable);
        } catch (Throwable) {
            // The task classification is authoritative even when evidence
            // persistence is the dependency that failed.
        }
    }
}
