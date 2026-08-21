<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Persistence;

final readonly class SmsDispatch
{
    public function __construct(
        public string $outboxKey,
        public int $tenantId,
        public int $recipientMemberId,
        private string $recipientPhoneDigest,
        private string $body,
        public string $jobKey,
        public bool $alreadyDelivered = false,
    ) {}

    public function recipientDigest(): string
    {
        return $this->recipientPhoneDigest;
    }

    public function messageBody(): string
    {
        return $this->body;
    }
}
