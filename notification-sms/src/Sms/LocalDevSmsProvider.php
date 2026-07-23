<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Sms;

final class LocalDevSmsProvider implements SmsProvider
{
    /** @var array<string, SmsReceipt> */
    private array $receipts = [];

    public function key(): string
    {
        return 'local-dev';
    }

    public function send(SmsSendRequest $request): SmsReceipt
    {
        $jobKey = $request->idempotencyKey();
        return $this->receipts[$jobKey] ??= new SmsReceipt(
            $this->key(),
            'dev_' . substr(hash('sha256', $jobKey . "\0" . $request->outboxKey()), 0, 32),
            'DEV_ACCEPTED',
        );
    }

    public function acceptedCount(): int
    {
        return count($this->receipts);
    }
}
