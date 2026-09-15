<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\NotificationSms\Application\AttachmentReference;
use PeanutAdmin\NotificationSms\Application\NotificationException;
use PeanutAdmin\NotificationSms\Application\NotificationMessage;
use PeanutAdmin\NotificationSms\Application\OutboxRecord;
use PeanutAdmin\NotificationSms\Application\RecipientSnapshot;
use PeanutAdmin\NotificationSms\Persistence\Model\SmsRateBucketRecord;
use PeanutAdmin\NotificationSms\Sms\SmsReceipt;
use think\db\BaseQuery;
use think\facade\Db;

/** Retains notification/outbox invariants while using ThinkPHP persistence. */
final class NotificationStore implements NotificationRepository
{
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
        $this->assertTenantActor($context);
        $existing = $this->templateRow($context->tenantId, $templateKey, true);
        $now = $this->now();
        if ($existing === null) {
            if ($expectedRevision !== null) {
                throw NotificationException::conflict();
            }
            Db::name('notification_template')->insert([
                'tenant_id' => $context->tenantId,
                'template_key' => $templateKey,
                'name' => $name,
                'subject_template' => $subjectTemplate,
                'body_template' => $bodyTemplate,
                'channels_json' => $this->json($channels),
                'variable_keys_json' => $this->json($variables),
                'status' => 'active',
                'created_by_member_id' => $context->memberId,
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            if ($expectedRevision === null || (int) $existing['revision'] !== $expectedRevision) {
                throw NotificationException::conflict();
            }
            $updated = Db::name('notification_template')
                ->where('tenant_id', $context->tenantId)
                ->where('template_key', $templateKey)
                ->where('revision', $expectedRevision)
                ->update([
                    'name' => $name,
                    'subject_template' => $subjectTemplate,
                    'body_template' => $bodyTemplate,
                    'channels_json' => $this->json($channels),
                    'variable_keys_json' => $this->json($variables),
                    'revision' => Db::raw('revision + 1'),
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw NotificationException::conflict();
            }
        }

        return $this->activeTemplate($context->tenantId, $templateKey);
    }

    /** @return array{template_key:string,name:string,subject_template:string,body_template:string,channels:list<string>,variables:list<string>,revision:int} */
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
        $now = $this->now();
        $messageId = (int) Db::name('notification_message')->insertGetId([
            'message_key' => $messageKey,
            'tenant_id' => $context->tenantId,
            'template_key' => $template['template_key'],
            'template_revision' => $template['revision'],
            'recipient_member_id' => $recipient->memberId,
            'recipient_account_id' => $recipient->accountId,
            'recipient_display_name' => $recipient->displayName,
            'subject' => $subject,
            'body' => $body,
            'status' => 'unread',
            'created_by_member_id' => $context->memberId,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'read_at' => null,
            'archived_at' => null,
        ]);
        foreach ($attachments as $attachment) {
            Db::name('notification_attachment')->insert([
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
            Db::name('notification_outbox')->insert([
                'outbox_key' => $outboxKey,
                'tenant_id' => $context->tenantId,
                'message_id' => $messageId,
                'channel' => $channel,
                'recipient_phone_masked' => $channel === 'sms' ? $recipient->phoneMasked : null,
                'recipient_phone_digest' => $channel === 'sms' ? $recipient->phoneDigest : null,
                'status' => 'pending',
                'revision' => 1,
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
        $query = Db::name('notification_message')
            ->where('tenant_id', $tenantId)
            ->where('recipient_member_id', $memberId);
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $total = (int) (clone $query)->count();
        $rows = $query->field('id')->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        return [
            'items' => array_values(array_map(
                fn(array $row): NotificationMessage => $this->messageById($tenantId, (int) $row['id'], $memberId),
                $rows,
            )),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ];
    }

    public function changeInbox(
        TenantContext $context,
        string $messageKey,
        string $action,
        int $expectedRevision,
    ): NotificationMessage {
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
        $now = $this->now();
        $updated = Db::name('notification_message')
            ->where('id', $row['id'])
            ->where('tenant_id', $context->tenantId)
            ->where('recipient_member_id', $context->memberId)
            ->where('status', 'unread')
            ->where('revision', $expectedRevision)
            ->update([
                'status' => 'read',
                'read_at' => $now,
                'updated_at' => $now,
                'revision' => Db::raw('revision + 1'),
            ]);
        if ($updated !== 1) {
            throw NotificationException::conflict();
        }
        $this->event($context->tenantId, (int) $row['id'], 'tenant.notification.read', $context->memberId, []);

        return $this->messageById($context->tenantId, (int) $row['id'], $context->memberId);
    }

    public function bulkChangeInbox(TenantContext $context, array $messageKeys, string $action): int
    {
        $changed = 0;
        $now = $this->now();
        foreach ($messageKeys as $messageKey) {
            $row = $this->messageRow($context->tenantId, $context->memberId, $messageKey, true);
            if ($row === null) {
                throw NotificationException::notFound();
            }
            $newStatus = $action === 'archive' ? 'archived' : 'read';
            if ($row['status'] === $newStatus || ($action === 'read' && $row['status'] === 'archived')) {
                continue;
            }
            $data = [
                'status' => $newStatus,
                'read_at' => $row['read_at'] ?? $now,
                'updated_at' => $now,
                'revision' => Db::raw('revision + 1'),
            ];
            if ($newStatus === 'archived') {
                $data['archived_at'] = $now;
            }
            $updated = Db::name('notification_message')
                ->where('id', $row['id'])
                ->where('tenant_id', $context->tenantId)
                ->where('recipient_member_id', $context->memberId)
                ->where('revision', $row['revision'])
                ->update($data);
            if ($updated !== 1) {
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
        $updated = Db::name('notification_outbox')
            ->where('id', $row['id'])
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'retryable'])
            ->whereNull('dispatch_job_key')
            ->update([
                'status' => 'queued',
                'dispatch_job_key' => $jobKey,
                'revision' => Db::raw('revision + 1'),
                'updated_at' => $this->now(),
            ]);
        if ($updated !== 1) {
            throw NotificationException::conflict();
        }
    }

    public function deliverInbox(int $tenantId, string $outboxKey, string $jobKey): void
    {
        $row = $this->assertJobOutbox($this->outboxRow($tenantId, $outboxKey, true), 'inbox', $jobKey);
        if ($row['status'] === 'delivered') {
            return;
        }
        $now = $this->now();
        $updated = Db::name('notification_outbox')
            ->where('id', $row['id'])
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['queued', 'processing'])
            ->update([
                'status' => 'delivered',
                'delivered_at' => $now,
                'updated_at' => $now,
                'revision' => Db::raw('revision + 1'),
            ]);
        if ($updated !== 1) {
            throw NotificationException::conflict();
        }
        $this->event($tenantId, (int) $row['message_id'], 'tenant.notification.delivered', null, ['channel' => 'inbox']);
    }

    public function beginSms(int $tenantId, string $outboxKey, string $jobKey): SmsDispatch
    {
        $row = $this->assertJobOutbox($this->outboxRow($tenantId, $outboxKey, true), 'sms', $jobKey);
        $message = $this->messageByInternalId($tenantId, (int) $row['message_id']);
        if ($row['status'] === 'delivered') {
            return new SmsDispatch($outboxKey, $tenantId, (int) $message['recipient_member_id'], (string) $row['recipient_phone_digest'], (string) $message['body'], $jobKey, true);
        }
        if (!in_array($row['status'], ['queued', 'retryable', 'processing'], true)) {
            throw NotificationException::conflict();
        }
        $updated = Db::name('notification_outbox')
            ->where('id', $row['id'])
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['queued', 'retryable', 'processing'])
            ->update([
                'status' => 'processing',
                'updated_at' => $this->now(),
                'revision' => Db::raw('revision + 1'),
            ]);
        if ($updated !== 1) {
            throw NotificationException::conflict();
        }

        return new SmsDispatch($outboxKey, $tenantId, (int) $message['recipient_member_id'], (string) $row['recipient_phone_digest'], (string) $message['body'], $jobKey);
    }

    public function reserveSmsRate(int $tenantId, string $recipientDigest): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $buckets = [];
        foreach ([['tenant', 60, 60], ['recipient:' . $recipientDigest, 3600, 5]] as [$key, $window, $limit]) {
            $query = SmsRateBucketRecord::where('tenant_id', $tenantId)->where('bucket_key', $key);
            $row = (clone $query)->lock(true)->find();
            if ($row === null) {
                SmsRateBucketRecord::duplicate(['bucket_key'])->insert([
                    'tenant_id' => $tenantId,
                    'bucket_key' => $key,
                    'window_seconds' => $window,
                    'window_started_at' => $this->date($now),
                    'send_count' => 0,
                    'updated_at' => $this->date($now),
                ]);
                $row = (clone $query)->lock(true)->find();
            }
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
            Db::name('sms_rate_bucket')->where('tenant_id', $tenantId)->where('bucket_key', $key)->update([
                'window_seconds' => $window,
                'window_started_at' => $this->date($started),
                'send_count' => $count,
                'updated_at' => $this->date($now),
            ]);
        }

        return true;
    }

    public function completeSms(SmsDispatch $dispatch, SmsReceipt $receipt): void
    {
        $row = $this->assertJobOutbox($this->outboxRow($dispatch->tenantId, $dispatch->outboxKey, true), 'sms', $dispatch->jobKey);
        if ($row['status'] === 'delivered') {
            return;
        }
        if ($row['status'] !== 'processing') {
            throw NotificationException::conflict();
        }
        $now = $this->now();
        $updated = Db::name('notification_outbox')
            ->where('id', $row['id'])
            ->where('tenant_id', $dispatch->tenantId)
            ->where('status', 'processing')
            ->update([
                'status' => 'delivered',
                'provider_key' => $receipt->providerKey,
                'provider_message_key' => $receipt->providerMessageKey,
                'provider_receipt_code' => $receipt->receiptCode,
                'last_error_code' => null,
                'delivered_at' => $now,
                'updated_at' => $now,
                'revision' => Db::raw('revision + 1'),
            ]);
        if ($updated !== 1) {
            throw NotificationException::conflict();
        }
        $this->event($dispatch->tenantId, (int) $row['message_id'], 'tenant.notification.delivered', null, [
            'channel' => 'sms', 'provider_key' => $receipt->providerKey, 'receipt_code' => $receipt->receiptCode,
        ]);
    }

    public function failSms(SmsDispatch $dispatch, string $safeCode, bool $retryable): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $safeCode) !== 1) {
            $safeCode = 'SMS_PROVIDER_UNAVAILABLE';
            $retryable = true;
        }
        $row = $this->assertJobOutbox($this->outboxRow($dispatch->tenantId, $dispatch->outboxKey, true), 'sms', $dispatch->jobKey);
        if ($row['status'] === 'delivered') {
            return;
        }
        $updated = Db::name('notification_outbox')
            ->where('id', $row['id'])
            ->where('tenant_id', $dispatch->tenantId)
            ->where('status', 'processing')
            ->update([
                'status' => $retryable ? 'retryable' : 'permanent_failed',
                'last_error_code' => $safeCode,
                'updated_at' => $this->now(),
                'revision' => Db::raw('revision + 1'),
            ]);
        if ($updated !== 1) {
            throw NotificationException::conflict();
        }
        $this->event($dispatch->tenantId, (int) $row['message_id'], 'tenant.notification.delivery_failed', null, [
            'channel' => 'sms', 'error_code' => $safeCode, 'retryable' => $retryable,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function templateRow(int $tenantId, string $templateKey, bool $lock): ?array
    {
        return $this->find(Db::name('notification_template')->where('tenant_id', $tenantId)->where('template_key', $templateKey), $lock);
    }

    /** @return array<string, mixed>|null */
    private function messageRow(int $tenantId, int $memberId, string $messageKey, bool $lock): ?array
    {
        return $this->find(Db::name('notification_message')->where('tenant_id', $tenantId)->where('recipient_member_id', $memberId)->where('message_key', $messageKey), $lock);
    }

    /** @return array<string, mixed> */
    private function messageByInternalId(int $tenantId, int $id): array
    {
        $row = Db::name('notification_message')->where('tenant_id', $tenantId)->where('id', $id)->find();
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
        $attachments = array_values(array_map(
            static fn(array $item): AttachmentReference => new AttachmentReference(
                (string) $item['file_key'], (string) $item['original_name'], (string) $item['media_type'], (int) $item['size_bytes'], (string) $item['sha256'],
            ),
            Db::name('notification_attachment')->where('tenant_id', $tenantId)->where('message_id', $id)
                ->field('file_key,original_name,media_type,size_bytes,sha256')->order('id')->select()->toArray(),
        ));
        return new NotificationMessage(
            (string) $row['message_key'], (string) $row['template_key'], (int) $row['template_revision'],
            (string) $row['subject'], (string) $row['body'], (string) $row['status'], (int) $row['revision'],
            $this->instant((string) $row['created_at']),
            $row['read_at'] === null ? null : $this->instant((string) $row['read_at']),
            $row['archived_at'] === null ? null : $this->instant((string) $row['archived_at']),
            $attachments,
        );
    }

    /** @return array<string, mixed>|null */
    private function outboxRow(int $tenantId, string $outboxKey, bool $lock): ?array
    {
        return $this->find(Db::name('notification_outbox')->where('tenant_id', $tenantId)->where('outbox_key', $outboxKey), $lock);
    }

    /** @param array<string, mixed> $row */
    private function mapOutbox(array $row): OutboxRecord
    {
        return new OutboxRecord((string) $row['outbox_key'], (int) $row['tenant_id'], (string) $row['channel'], (string) $row['status'], $row['dispatch_job_key'] === null ? null : (string) $row['dispatch_job_key']);
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function assertJobOutbox(?array $row, string $channel, string $jobKey): array
    {
        if ($row === null || !hash_equals($channel, (string) $row['channel']) || $row['dispatch_job_key'] === null || !hash_equals((string) $row['dispatch_job_key'], $jobKey)) {
            throw NotificationException::notFound();
        }
        return $row;
    }

    private function assertTenantActor(TenantContext $context): void
    {
        $id = Db::name('tenant_member')->where('tenant_id', $context->tenantId)->where('id', $context->memberId)
            ->where('account_id', $context->accountId)->where('status', 'active')->value('id');
        if ($id === null) {
            throw NotificationException::denied();
        }
    }

    private function assertRecipientSnapshot(int $tenantId, RecipientSnapshot $recipient): void
    {
        $accountId = Db::name('tenant_member')->where('tenant_id', $tenantId)->where('id', $recipient->memberId)
            ->where('status', 'active')->value('account_id');
        if ((!is_int($accountId) && !is_string($accountId)) || (int) $accountId !== $recipient->accountId) {
            throw NotificationException::recipientUnavailable();
        }
    }

    /** @param array<string, mixed> $metadata */
    private function event(int $tenantId, int $messageId, string $eventKey, ?int $actorMemberId, array $metadata): void
    {
        Db::name('notification_event')->insert([
            'tenant_id' => $tenantId, 'message_id' => $messageId, 'event_key' => $eventKey,
            'actor_member_id' => $actorMemberId, 'metadata_json' => $this->json($metadata), 'occurred_at' => $this->now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function find(BaseQuery $query, bool $lock): ?array
    {
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return is_array($row) ? $row : null;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
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

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
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
