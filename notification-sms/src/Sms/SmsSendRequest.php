<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Sms;

use PeanutAdmin\NotificationSms\Application\NotificationException;

final readonly class SmsSendRequest
{
    public function __construct(
        private string $jobKey,
        private int $tenantId,
        private string $outboxKey,
        private string $recipientE164,
        private string $body,
    ) {
        if (preg_match('/^job_[0-9a-f]{32}$/D', $jobKey) !== 1 || $tenantId < 1
            || preg_match('/^outbox_[0-9a-f]{32}$/D', $outboxKey) !== 1
            || preg_match('/^\+[1-9][0-9]{7,14}$/D', $recipientE164) !== 1
            || $body === '' || mb_strlen($body) > 1000
        ) {
            throw NotificationException::invalid('SMS_REQUEST_INVALID');
        }
    }

    public function recipientNumber(): string
    {
        return $this->recipientE164;
    }

    public function idempotencyKey(): string
    {
        return $this->jobKey;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function outboxKey(): string
    {
        return $this->outboxKey;
    }

    public function body(): string
    {
        return $this->body;
    }
}
