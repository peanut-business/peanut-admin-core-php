<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Persistence;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\NotificationSms\Application\AttachmentReference;
use PeanutAdmin\NotificationSms\Application\NotificationMessage;
use PeanutAdmin\NotificationSms\Application\OutboxRecord;
use PeanutAdmin\NotificationSms\Application\RecipientSnapshot;

interface NotificationRepository
{
    public function transaction(callable $operation): mixed;

    /**
     * @param list<string> $channels
     * @param list<string> $variables
     * @return array<string, mixed>
     */
    public function putTemplate(
        TenantContext $context,
        string $templateKey,
        string $name,
        string $subjectTemplate,
        string $bodyTemplate,
        array $channels,
        array $variables,
        ?int $expectedRevision,
    ): array;

    /** @return array<string, mixed> */
    public function activeTemplate(int $tenantId, string $templateKey): array;

    /**
     * @param list<AttachmentReference> $attachments
     * @return array{message: NotificationMessage, outbox: list<OutboxRecord>}
     */
    public function createMessage(
        TenantContext $context,
        string $messageKey,
        array $template,
        RecipientSnapshot $recipient,
        string $subject,
        string $body,
        array $attachments,
    ): array;

    /** @return array{items: list<NotificationMessage>, page: int, page_size: int, total: int} */
    public function inbox(int $tenantId, int $memberId, string $status, int $page, int $pageSize): array;

    public function changeInbox(
        TenantContext $context,
        string $messageKey,
        string $action,
        int $expectedRevision,
    ): NotificationMessage;

    /** @param list<string> $messageKeys */
    public function bulkChangeInbox(TenantContext $context, array $messageKeys, string $action): int;

    public function outboxForSubmission(int $tenantId, string $outboxKey): OutboxRecord;

    public function bindJob(int $tenantId, string $outboxKey, string $jobKey): void;

    public function deliverInbox(int $tenantId, string $outboxKey, string $jobKey): void;

    public function beginSms(int $tenantId, string $outboxKey, string $jobKey): SmsDispatch;

    public function reserveSmsRate(int $tenantId, string $recipientDigest): bool;

    public function completeSms(SmsDispatch $dispatch, \PeanutAdmin\NotificationSms\Sms\SmsReceipt $receipt): void;

    public function failSms(SmsDispatch $dispatch, string $safeCode, bool $retryable): void;
}
