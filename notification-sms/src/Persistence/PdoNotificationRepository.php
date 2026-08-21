<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\NotificationSms\Application\AttachmentReference;
use PeanutAdmin\NotificationSms\Application\NotificationException;
use PeanutAdmin\NotificationSms\Application\NotificationMessage;
use PeanutAdmin\NotificationSms\Application\OutboxRecord;
use PeanutAdmin\NotificationSms\Application\RecipientSnapshot;
use PeanutAdmin\NotificationSms\Sms\SmsReceipt;
use Throwable;

final readonly class PdoNotificationRepository implements NotificationRepository
{
    public function __construct(private PDO $pdo) {}

    public function transaction(callable $operation): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function putTemplate(
        TenantContext $context,
        string $templateKey,
        string $name,
        string $subjectTemplate,
        string $bodyTemplate,
        array $channels,
        array $variables,
        ?int $expectedRevision,
    ): array {
        return $this->transaction(function () use (
            $context,
            $templateKey,
            $name,
            $subjectTemplate,
            $bodyTemplate,
            $channels,
            $variables,
            $expectedRevision,
        ): array {
            $this->assertTenantActor($context);
            $existing = $this->templateRow($context->tenantId, $templateKey, true);
            $now = $this->databaseNow();
            if ($existing === null) {
                if ($expectedRevision !== null) {
                    throw NotificationException::conflict();
                }
                $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_notification_template (
  tenant_id, template_key, name, subject_template, body_template, channels_json,
  variable_keys_json, status, created_by_member_id, revision, created_at, updated_at
) VALUES (
  :tenant_id, :template_key, :name, :subject_template, :body_template, :channels_json,
  :variable_keys_json, 'active', :member_id, 1, :created_at, :updated_at
)
SQL);
                $statement->execute([
                    'tenant_id' => $context->tenantId,
                    'template_key' => $templateKey,
                    'name' => $name,
                    'subject_template' => $subjectTemplate,
                    'body_template' => $bodyTemplate,
                    'channels_json' => $this->json($channels),
                    'variable_keys_json' => $this->json($variables),
                    'member_id' => $context->memberId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                if ($expectedRevision === null || (int) $existing['revision'] !== $expectedRevision) {
                    throw NotificationException::conflict();
                }
                $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_notification_template
SET name = :name, subject_template = :subject_template, body_template = :body_template,
    channels_json = :channels_json, variable_keys_json = :variable_keys_json,
    revision = revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND template_key = :template_key AND revision = :revision
SQL);
                $statement->execute([
                    'name' => $name,
                    'subject_template' => $subjectTemplate,
                    'body_template' => $bodyTemplate,
                    'channels_json' => $this->json($channels),
                    'variable_keys_json' => $this->json($variables),
                    'updated_at' => $now,
                    'tenant_id' => $context->tenantId,
                    'template_key' => $templateKey,
                    'revision' => $expectedRevision,
                ]);
                if ($statement->rowCount() !== 1) {
                    throw NotificationException::conflict();
                }
            }

            return $this->activeTemplate($context->tenantId, $templateKey);
        });
    }

    public function activeTemplate(int $tenantId, string $templateKey): array
    {
        $row = $this->templateRow($tenantId, $templateKey, false);
        if ($row === null || $row['status'] !== 'active') {
            throw NotificationException::notFound();
        }

        return [
            'template_key' => (string) $row['template_key'],
            'name' => (string) $row['name'],
            'subject_template' => (string) $row['subject_template'],
            'body_template' => (string) $row['body_template'],
            'channels' => $this->stringList((string) $row['channels_json'], ['inbox', 'sms']),
            'variables' => $this->stringList((string) $row['variable_keys_json']),
            'revision' => (int) $row['revision'],
        ];
    }

    public function createMessage(
        TenantContext $context,
        string $messageKey,
        array $template,
        RecipientSnapshot $recipient,
        string $subject,
        string $body,
        array $attachments,
    ): array {
        $this->assertTenantActor($context);
        $this->assertRecipientSnapshot($context->tenantId, $recipient);
        $now = $this->databaseNow();
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_notification_message (
  message_key, tenant_id, template_key, template_revision, recipient_member_id, recipient_account_id,
  recipient_display_name, subject, body, status, created_by_member_id, revision,
  created_at, updated_at, read_at, archived_at
) VALUES (
  :message_key, :tenant_id, :template_key, :template_revision, :recipient_member_id, :recipient_account_id,
  :recipient_display_name, :subject, :body, 'unread', :created_by_member_id, 1,
  :created_at, :updated_at, NULL, NULL
)
SQL);
        $statement->execute([
            'message_key' => $messageKey,
            'tenant_id' => $context->tenantId,
            'template_key' => $template['template_key'],
            'template_revision' => $template['revision'],
            'recipient_member_id' => $recipient->memberId,
            'recipient_account_id' => $recipient->accountId,
            'recipient_display_name' => $recipient->displayName,
            'subject' => $subject,
            'body' => $body,
            'created_by_member_id' => $context->memberId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $messageId = (int) $this->pdo->lastInsertId();
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof AttachmentReference) {
                throw NotificationException::attachmentUnavailable();
            }
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_notification_attachment (
  tenant_id, message_id, file_key, original_name, media_type, size_bytes, sha256
) VALUES (:tenant_id, :message_id, :file_key, :original_name, :media_type, :size_bytes, :sha256)
SQL);
            $insert->execute([
                'tenant_id' => $context->tenantId,
                'message_id' => $messageId,
                'file_key' => $attachment->fileKey,
                'original_name' => $attachment->originalName,
                'media_type' => $attachment->mediaType,
                'size_bytes' => $attachment->sizeBytes,
                'sha256' => $attachment->sha256,
            ]);
        }

        $outbox = [];
        foreach ($template['channels'] as $channel) {
            $outboxKey = 'outbox_' . bin2hex(random_bytes(16));
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_notification_outbox (
  outbox_key, tenant_id, message_id, channel, recipient_phone_masked,
  recipient_phone_digest, status, revision, created_at, updated_at
) VALUES (
  :outbox_key, :tenant_id, :message_id, :channel, :phone_masked,
  :phone_digest, 'pending', 1, :created_at, :updated_at
)
SQL);
            $insert->execute([
                'outbox_key' => $outboxKey,
                'tenant_id' => $context->tenantId,
                'message_id' => $messageId,
                'channel' => $channel,
                'phone_masked' => $channel === 'sms' ? $recipient->phoneMasked : null,
                'phone_digest' => $channel === 'sms' ? $recipient->phoneDigest : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $outbox[] = new OutboxRecord($outboxKey, $context->tenantId, $channel, 'pending', null);
        }
        $this->event($context->tenantId, $messageId, 'tenant.notification.created', $context->memberId, [
            'channel_count' => count($outbox),
            'attachment_count' => count($attachments),
            'template_key' => $template['template_key'],
        ]);

        return [
            'message' => $this->messageById($context->tenantId, $messageId, $recipient->memberId),
            'outbox' => $outbox,
        ];
    }

    public function inbox(int $tenantId, int $memberId, string $status, int $page, int $pageSize): array
    {
        $statusSql = $status === 'all' ? '' : ' AND status = :status';
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM pa_notification_message WHERE tenant_id = :tenant_id AND recipient_member_id = :member_id' . $statusSql,
        );
        $params = ['tenant_id' => $tenantId, 'member_id' => $memberId];
        if ($status !== 'all') {
            $params['status'] = $status;
        }
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT id FROM pa_notification_message WHERE tenant_id = :tenant_id AND recipient_member_id = :member_id'
            . $statusSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':member_id', $memberId, PDO::PARAM_INT);
        if ($status !== 'all') {
            $statement->bindValue(':status', $status);
        }
        $statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $statement->execute();
        $items = [];
        while (($id = $statement->fetchColumn()) !== false) {
            $items[] = $this->messageById($tenantId, (int) $id, $memberId);
        }

        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    public function changeInbox(
        TenantContext $context,
        string $messageKey,
        string $action,
        int $expectedRevision,
    ): NotificationMessage {
        return $this->transaction(function () use ($context, $messageKey, $action, $expectedRevision): NotificationMessage {
            $row = $this->messageRow($context->tenantId, $context->memberId, $messageKey, true);
            if ($row === null) {
                throw NotificationException::notFound();
            }
            if ((int) $row['revision'] !== $expectedRevision) {
                throw NotificationException::conflict();
            }
            if ($action === 'read' && in_array($row['status'], ['read', 'archived'], true)) {
                return $this->messageById($context->tenantId, (int) $row['id'], $context->memberId);
            }
            if ($action !== 'read') {
                throw NotificationException::invalid();
            }
            $now = $this->databaseNow();
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_notification_message
SET status = 'read', read_at = :read_at, updated_at = :updated_at, revision = revision + 1
WHERE id = :id AND tenant_id = :tenant_id AND recipient_member_id = :member_id
  AND status = 'unread' AND revision = :revision
SQL);
            $statement->execute([
                'read_at' => $now,
                'updated_at' => $now,
                'id' => $row['id'],
                'tenant_id' => $context->tenantId,
                'member_id' => $context->memberId,
                'revision' => $expectedRevision,
            ]);
            if ($statement->rowCount() !== 1) {
                throw NotificationException::conflict();
            }
            $this->event($context->tenantId, (int) $row['id'], 'tenant.notification.read', $context->memberId, []);
            return $this->messageById($context->tenantId, (int) $row['id'], $context->memberId);
        });
    }

    public function bulkChangeInbox(TenantContext $context, array $messageKeys, string $action): int
    {
        return $this->transaction(function () use ($context, $messageKeys, $action): int {
            $changed = 0;
            $now = $this->databaseNow();
            foreach ($messageKeys as $messageKey) {
                $row = $this->messageRow($context->tenantId, $context->memberId, $messageKey, true);
                if ($row === null) {
                    throw NotificationException::notFound();
                }
                $newStatus = $action === 'archive' ? 'archived' : 'read';
                if ($row['status'] === $newStatus || ($action === 'read' && $row['status'] === 'archived')) {
                    continue;
                }
                $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_notification_message
SET status = :new_status,
    read_at = COALESCE(read_at, :read_at),
    archived_at = CASE WHEN :archive_status = 'archived' THEN :archived_at ELSE archived_at END,
    updated_at = :updated_at,
    revision = revision + 1
WHERE id = :id AND tenant_id = :tenant_id AND recipient_member_id = :member_id AND revision = :revision
SQL);
                $statement->execute([
                    'new_status' => $newStatus,
                    'archive_status' => $newStatus,
                    'read_at' => $now,
                    'archived_at' => $now,
                    'updated_at' => $now,
                    'id' => $row['id'],
                    'tenant_id' => $context->tenantId,
                    'member_id' => $context->memberId,
                    'revision' => $row['revision'],
                ]);
                if ($statement->rowCount() !== 1) {
                    throw NotificationException::conflict();
                }
                ++$changed;
                $this->event(
                    $context->tenantId,
                    (int) $row['id'],
                    $action === 'archive' ? 'tenant.notification.archived' : 'tenant.notification.read',
                    $context->memberId,
                    ['bulk' => true],
                );
            }
            return $changed;
        });
    }

    public function outboxForSubmission(int $tenantId, string $outboxKey): OutboxRecord
    {
        $row = $this->outboxRow($tenantId, $outboxKey, false);
        if ($row === null || !in_array($row['status'], ['pending', 'retryable', 'queued'], true)) {
            throw NotificationException::notFound();
        }
        return $this->mapOutbox($row);
    }

    public function bindJob(int $tenantId, string $outboxKey, string $jobKey): void
    {
        $this->transaction(function () use ($tenantId, $outboxKey, $jobKey): void {
            $row = $this->outboxRow($tenantId, $outboxKey, true);
            if ($row === null) {
                throw NotificationException::notFound();
            }
            if ($row['status'] === 'queued' && hash_equals((string) $row['dispatch_job_key'], $jobKey)) {
                return;
            }
            if (!in_array($row['status'], ['pending', 'retryable'], true) || $row['dispatch_job_key'] !== null) {
                throw NotificationException::conflict();
            }
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_notification_outbox
SET status = 'queued', dispatch_job_key = :job_key, revision = revision + 1, updated_at = :now
WHERE id = :id AND tenant_id = :tenant_id AND status IN ('pending','retryable') AND dispatch_job_key IS NULL
SQL);
            $statement->execute([
                'job_key' => $jobKey,
                'now' => $this->databaseNow(),
                'id' => $row['id'],
                'tenant_id' => $tenantId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw NotificationException::conflict();
            }
        });
    }

    public function deliverInbox(int $tenantId, string $outboxKey, string $jobKey): void
    {
        $this->transaction(function () use ($tenantId, $outboxKey, $jobKey): void {
            $row = $this->outboxRow($tenantId, $outboxKey, true);
            $this->assertJobOutbox($row, 'inbox', $jobKey);
            if ($row['status'] === 'delivered') {
                return;
            }
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_notification_outbox
SET status = 'delivered', delivered_at = :delivered_at, updated_at = :updated_at, revision = revision + 1
WHERE id = :id AND tenant_id = :tenant_id AND status IN ('queued','processing')
SQL);
            $now = $this->databaseNow();
            $statement->execute(['delivered_at' => $now, 'updated_at' => $now, 'id' => $row['id'], 'tenant_id' => $tenantId]);
            if ($statement->rowCount() !== 1) {
                throw NotificationException::conflict();
            }
            $this->event($tenantId, (int) $row['message_id'], 'tenant.notification.delivered', null, ['channel' => 'inbox']);
        });
    }

    public function beginSms(int $tenantId, string $outboxKey, string $jobKey): SmsDispatch
    {
        return $this->transaction(function () use ($tenantId, $outboxKey, $jobKey): SmsDispatch {
            $row = $this->outboxRow($tenantId, $outboxKey, true);
            $this->assertJobOutbox($row, 'sms', $jobKey);
            $message = $this->messageByInternalId($tenantId, (int) $row['message_id']);
            if ($row['status'] === 'delivered') {
                return new SmsDispatch(
                    $outboxKey,
                    $tenantId,
                    (int) $message['recipient_member_id'],
                    (string) $row['recipient_phone_digest'],
                    (string) $message['body'],
                    $jobKey,
                    true,
                );
            }
            if (!in_array($row['status'], ['queued', 'retryable', 'processing'], true)) {
                throw NotificationException::conflict();
            }
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_notification_outbox
SET status = 'processing', updated_at = :now, revision = revision + 1
WHERE id = :id AND tenant_id = :tenant_id AND status IN ('queued','retryable','processing')
SQL);
            $statement->execute(['now' => $this->databaseNow(), 'id' => $row['id'], 'tenant_id' => $tenantId]);
            return new SmsDispatch(
                $outboxKey,
                $tenantId,
                (int) $message['recipient_member_id'],
                (string) $row['recipient_phone_digest'],
                (string) $message['body'],
                $jobKey,
            );
        });
    }

    public function reserveSmsRate(int $tenantId, string $recipientDigest): bool
    {
        return $this->transaction(function () use ($tenantId, $recipientDigest): bool {
            $now = new DateTimeImmutable($this->databaseNow(), new DateTimeZone('UTC'));
            $buckets = [];
            foreach ([['tenant', 60, 60], ['recipient:' . $recipientDigest, 3600, 5]] as [$key, $window, $limit]) {
                $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_sms_rate_bucket (tenant_id, bucket_key, window_seconds, window_started_at, send_count, updated_at)
VALUES (:tenant_id, :bucket_key, :window_seconds, :window_started_at, 0, :updated_at)
ON DUPLICATE KEY UPDATE bucket_key = VALUES(bucket_key)
SQL);
                $insert->execute([
                    'tenant_id' => $tenantId,
                    'bucket_key' => $key,
                    'window_seconds' => $window,
                    'window_started_at' => $this->date($now),
                    'updated_at' => $this->date($now),
                ]);
                $select = $this->pdo->prepare(<<<'SQL'
SELECT window_started_at, send_count FROM pa_sms_rate_bucket
WHERE tenant_id = :tenant_id AND bucket_key = :bucket_key FOR UPDATE
SQL);
                $select->execute(['tenant_id' => $tenantId, 'bucket_key' => $key]);
                $row = $select->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    throw NotificationException::conflict();
                }
                $started = new DateTimeImmutable((string) $row['window_started_at'], new DateTimeZone('UTC'));
                $count = (int) $row['send_count'];
                if ($started->modify('+' . $window . ' seconds') <= $now) {
                    $started = $now;
                    $count = 0;
                }
                if ($count >= $limit) {
                    return false;
                }
                $buckets[] = [$key, $window, $started, $count + 1];
            }
            foreach ($buckets as [$key, $window, $started, $count]) {
                $update = $this->pdo->prepare(<<<'SQL'
UPDATE pa_sms_rate_bucket
SET window_seconds = :window_seconds, window_started_at = :started_at,
    send_count = :send_count, updated_at = :now
WHERE tenant_id = :tenant_id AND bucket_key = :bucket_key
SQL);
                $update->execute([
                    'window_seconds' => $window,
                    'started_at' => $this->date($started),
                    'send_count' => $count,
                    'now' => $this->date($now),
                    'tenant_id' => $tenantId,
                    'bucket_key' => $key,
                ]);
            }
            return true;
        });
    }

    public function completeSms(SmsDispatch $dispatch, SmsReceipt $receipt): void
    {
        $this->transaction(function () use ($dispatch, $receipt): void {
            $row = $this->outboxRow($dispatch->tenantId, $dispatch->outboxKey, true);
            $this->assertJobOutbox($row, 'sms', $dispatch->jobKey);
            if ($row['status'] === 'delivered') {
                return;
            }
            if ($row['status'] !== 'processing') {
                throw NotificationException::conflict();
            }
            $now = $this->databaseNow();
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_notification_outbox
SET status = 'delivered', provider_key = :provider_key,
    provider_message_key = :provider_message_key, provider_receipt_code = :receipt_code,
    last_error_code = NULL, delivered_at = :delivered_at, updated_at = :updated_at, revision = revision + 1
WHERE id = :id AND tenant_id = :tenant_id AND status = 'processing'
SQL);
            $statement->execute([
                'provider_key' => $receipt->providerKey,
                'provider_message_key' => $receipt->providerMessageKey,
                'receipt_code' => $receipt->receiptCode,
                'delivered_at' => $now,
                'updated_at' => $now,
                'id' => $row['id'],
                'tenant_id' => $dispatch->tenantId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw NotificationException::conflict();
            }
            $this->event($dispatch->tenantId, (int) $row['message_id'], 'tenant.notification.delivered', null, [
                'channel' => 'sms',
                'provider_key' => $receipt->providerKey,
                'receipt_code' => $receipt->receiptCode,
            ]);
        });
    }

    public function failSms(SmsDispatch $dispatch, string $safeCode, bool $retryable): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $safeCode) !== 1) {
            $safeCode = 'SMS_PROVIDER_UNAVAILABLE';
            $retryable = true;
        }
        $this->transaction(function () use ($dispatch, $safeCode, $retryable): void {
            $row = $this->outboxRow($dispatch->tenantId, $dispatch->outboxKey, true);
            $this->assertJobOutbox($row, 'sms', $dispatch->jobKey);
            if ($row['status'] === 'delivered') {
                return;
            }
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_notification_outbox
SET status = :status, last_error_code = :error_code, updated_at = :now, revision = revision + 1
WHERE id = :id AND tenant_id = :tenant_id AND status = 'processing'
SQL);
            $statement->execute([
                'status' => $retryable ? 'retryable' : 'permanent_failed',
                'error_code' => $safeCode,
                'now' => $this->databaseNow(),
                'id' => $row['id'],
                'tenant_id' => $dispatch->tenantId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw NotificationException::conflict();
            }
            $this->event($dispatch->tenantId, (int) $row['message_id'], 'tenant.notification.delivery_failed', null, [
                'channel' => 'sms',
                'error_code' => $safeCode,
                'retryable' => $retryable,
            ]);
        });
    }

    /** @return array<string, mixed>|null */
    private function templateRow(int $tenantId, string $templateKey, bool $lock): ?array
    {
        $sql = 'SELECT * FROM pa_notification_template WHERE tenant_id = :tenant_id AND template_key = :template_key';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['tenant_id' => $tenantId, 'template_key' => $templateKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function messageRow(int $tenantId, int $memberId, string $messageKey, bool $lock): ?array
    {
        $sql = 'SELECT * FROM pa_notification_message WHERE tenant_id = :tenant_id AND recipient_member_id = :member_id AND message_key = :message_key';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['tenant_id' => $tenantId, 'member_id' => $memberId, 'message_key' => $messageKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function messageByInternalId(int $tenantId, int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pa_notification_message WHERE tenant_id = :tenant_id AND id = :id');
        $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw NotificationException::notFound();
        }
        return $row;
    }

    private function messageById(int $tenantId, int $id, int $memberId): NotificationMessage
    {
        $row = $this->messageByInternalId($tenantId, $id);
        if ((int) $row['recipient_member_id'] !== $memberId) {
            throw NotificationException::notFound();
        }
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT file_key, original_name, media_type, size_bytes, sha256
FROM pa_notification_attachment WHERE tenant_id = :tenant_id AND message_id = :message_id ORDER BY id
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'message_id' => $id]);
        $attachments = [];
        while (($attachment = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $attachments[] = new AttachmentReference(
                (string) $attachment['file_key'],
                (string) $attachment['original_name'],
                (string) $attachment['media_type'],
                (int) $attachment['size_bytes'],
                (string) $attachment['sha256'],
            );
        }
        return new NotificationMessage(
            (string) $row['message_key'],
            (string) $row['template_key'],
            (int) $row['template_revision'],
            (string) $row['subject'],
            (string) $row['body'],
            (string) $row['status'],
            (int) $row['revision'],
            $this->instant((string) $row['created_at']),
            $row['read_at'] === null ? null : $this->instant((string) $row['read_at']),
            $row['archived_at'] === null ? null : $this->instant((string) $row['archived_at']),
            $attachments,
        );
    }

    /** @return array<string, mixed>|null */
    private function outboxRow(int $tenantId, string $outboxKey, bool $lock): ?array
    {
        $sql = 'SELECT * FROM pa_notification_outbox WHERE tenant_id = :tenant_id AND outbox_key = :outbox_key';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['tenant_id' => $tenantId, 'outbox_key' => $outboxKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function mapOutbox(array $row): OutboxRecord
    {
        return new OutboxRecord(
            (string) $row['outbox_key'],
            (int) $row['tenant_id'],
            (string) $row['channel'],
            (string) $row['status'],
            $row['dispatch_job_key'] === null ? null : (string) $row['dispatch_job_key'],
        );
    }

    private function assertJobOutbox(?array $row, string $channel, string $jobKey): void
    {
        if ($row === null || !hash_equals($channel, (string) $row['channel'])
            || $row['dispatch_job_key'] === null || !hash_equals((string) $row['dispatch_job_key'], $jobKey)
        ) {
            throw NotificationException::notFound();
        }
    }

    private function assertTenantActor(TenantContext $context): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT account_id FROM pa_tenant_member
WHERE tenant_id = :tenant_id AND id = :member_id AND account_id = :account_id AND status = 'active'
SQL);
        $statement->execute([
            'tenant_id' => $context->tenantId,
            'member_id' => $context->memberId,
            'account_id' => $context->accountId,
        ]);
        if ($statement->fetchColumn() === false) {
            throw NotificationException::denied();
        }
    }

    private function assertRecipientSnapshot(int $tenantId, RecipientSnapshot $recipient): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT account_id FROM pa_tenant_member
WHERE tenant_id = :tenant_id AND id = :member_id AND status = 'active'
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'member_id' => $recipient->memberId]);
        $accountId = $statement->fetchColumn();
        if (!is_int($accountId) && !is_string($accountId)) {
            throw NotificationException::recipientUnavailable();
        }
        if ((int) $accountId !== $recipient->accountId) {
            throw NotificationException::recipientUnavailable();
        }
    }

    /** @param array<string, int|string|bool> $metadata */
    private function event(int $tenantId, int $messageId, string $eventKey, ?int $actorMemberId, array $metadata): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_notification_event (tenant_id, message_id, event_key, actor_member_id, metadata_json, occurred_at)
VALUES (:tenant_id, :message_id, :event_key, :actor_member_id, :metadata_json, :occurred_at)
SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'message_id' => $messageId,
            'event_key' => $eventKey,
            'actor_member_id' => $actorMemberId,
            'metadata_json' => $this->json($metadata),
            'occurred_at' => $this->databaseNow(),
        ]);
    }

    private function databaseNow(): string
    {
        $value = $this->pdo->query("SELECT DATE_FORMAT(UTC_TIMESTAMP(3), '%Y-%m-%d %H:%i:%s.%f')")->fetchColumn();
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $value) !== 1) {
            throw NotificationException::conflict();
        }
        return substr($value, 0, 23);
    }

    private function instant(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s.v') !== $value) {
            throw NotificationException::conflict();
        }
        return $date->format('Y-m-d\TH:i:s.v\Z');
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw NotificationException::invalid();
        }
    }

    /** @param list<string> $allowed @return list<string> */
    private function stringList(string $json, array $allowed = []): array
    {
        try {
            $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw NotificationException::conflict();
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw NotificationException::conflict();
        }
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item) || isset($seen[$item]) || ($allowed !== [] && !in_array($item, $allowed, true))) {
                throw NotificationException::conflict();
            }
            $seen[$item] = true;
        }
        return array_keys($seen);
    }
}
