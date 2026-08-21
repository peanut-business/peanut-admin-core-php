<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach ([
        'PeanutAdmin\\NotificationSms\\' => $root . '/packages/php/notification-sms/src/',
        'PeanutAdmin\\TaskJob\\' => $root . '/packages/php/task-job/src/',
        'PeanutAdmin\\Kernel\\' => $root . '/packages/php/kernel/src/',
    ] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\NotificationSms\Application\AttachmentReference;
use PeanutAdmin\NotificationSms\Application\AttachmentResolver;
use PeanutAdmin\NotificationSms\Application\NotificationException;
use PeanutAdmin\NotificationSms\Application\NotificationMessage;
use PeanutAdmin\NotificationSms\Application\NotificationService;
use PeanutAdmin\NotificationSms\Application\OutboxRecord;
use PeanutAdmin\NotificationSms\Application\RecipientResolver;
use PeanutAdmin\NotificationSms\Application\RecipientSnapshot;
use PeanutAdmin\NotificationSms\Application\TemplateRenderer;
use PeanutAdmin\NotificationSms\Database\Schema;
use PeanutAdmin\NotificationSms\Package;
use PeanutAdmin\NotificationSms\Persistence\NotificationRepository;
use PeanutAdmin\NotificationSms\Persistence\PdoNotificationRepository;
use PeanutAdmin\NotificationSms\Persistence\SmsDispatch;
use PeanutAdmin\NotificationSms\Sms\LocalDevSmsProvider;
use PeanutAdmin\NotificationSms\Sms\SmsProvider;
use PeanutAdmin\NotificationSms\Sms\SmsProviderException;
use PeanutAdmin\NotificationSms\Sms\SmsReceipt;
use PeanutAdmin\NotificationSms\Sms\SmsRecipient;
use PeanutAdmin\NotificationSms\Sms\SmsRecipientResolver;
use PeanutAdmin\NotificationSms\Task\InboxTaskHandler;
use PeanutAdmin\NotificationSms\Task\OutboxTaskSubmissionProvider;
use PeanutAdmin\NotificationSms\Task\SmsTaskHandler;
use PeanutAdmin\TaskJob\Execution\JobExecution;
use PeanutAdmin\TaskJob\Execution\RetryableTaskException;

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function expectCode(string $code, callable $operation, string $message): void
{
    try {
        $operation();
    } catch (NotificationException $exception) {
        same($code, $exception->problemCode, $message);
        return;
    }
    throw new RuntimeException($message . ' did not fail');
}

function context(string $operation, int $tenantId = 101, int $memberId = 501): AuthorizedOperationContext
{
    $session = new ValidatedTenantSession(
        1,
        'sess_' . str_repeat('a', 32),
        $tenantId,
        301,
        $memberId,
        'admin-web',
        new \DateTimeImmutable('2026-07-24T10:00:00Z'),
        1,
    );
    $tenant = TenantContext::fromValidatedSession($session, 'req_notification_feature');
    return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
        $tenant,
        Package::RESOURCE_KEY,
        $operation,
        [],
        hash('sha256', 'basis'),
    ));
}

final class MemoryRepository implements NotificationRepository
{
    public ?SmsReceipt $receipt = null;
    public ?array $failure = null;
    public bool $rateAllowed = true;
    public bool $beginFails = false;
    public bool $failureWriteFails = false;
    public bool $inboxDeliveryFails = false;
    public int $created = 0;
    public string $smsDigest;

    public function __construct()
    {
        $this->smsDigest = (new SmsRecipient('+8613800138000', str_repeat('k', 32)))->digest;
    }

    public function transaction(callable $operation): mixed
    {
        return $operation();
    }
    public function putTemplate(TenantContext $context, string $templateKey, string $name, string $subjectTemplate, string $bodyTemplate, array $channels, array $variables, ?int $expectedRevision): array
    {
        return ['template_key' => $templateKey, 'revision' => 1];
    }
    public function activeTemplate(int $tenantId, string $templateKey): array
    {
        if ($tenantId !== 101 || $templateKey !== 'security.alert') {
            throw NotificationException::notFound();
        }
        return [
            'template_key' => 'security.alert', 'name' => 'Security alert',
            'subject_template' => 'Alert {{code}}', 'body_template' => 'Review {{code}}',
            'channels' => ['inbox', 'sms'], 'variables' => ['code'], 'revision' => 1,
        ];
    }
    public function createMessage(TenantContext $context, string $messageKey, array $template, RecipientSnapshot $recipient, string $subject, string $body, array $attachments): array
    {
        ++$this->created;
        same(101, $context->tenantId, 'message tenant');
        same(501, $recipient->memberId, 'recipient snapshot');
        $message = new NotificationMessage($messageKey, 'security.alert', 1, $subject, $body, 'unread', 1, '2026-07-24T10:00:00.000Z', null, null, $attachments);
        return ['message' => $message, 'outbox' => [
            new OutboxRecord('outbox_' . str_repeat('1', 32), 101, 'inbox', 'pending', null),
            new OutboxRecord('outbox_' . str_repeat('2', 32), 101, 'sms', 'pending', null),
        ]];
    }
    public function inbox(int $tenantId, int $memberId, string $status, int $page, int $pageSize): array
    {
        return ['items' => [], 'page' => 1, 'page_size' => 20, 'total' => 0];
    }
    public function changeInbox(TenantContext $context, string $messageKey, string $action, int $expectedRevision): NotificationMessage
    {
        throw new LogicException('unused');
    }
    public function bulkChangeInbox(TenantContext $context, array $messageKeys, string $action): int
    {
        throw new LogicException('unused');
    }
    public function outboxForSubmission(int $tenantId, string $outboxKey): OutboxRecord
    {
        throw new LogicException('unused');
    }
    public function bindJob(int $tenantId, string $outboxKey, string $jobKey): void
    {
        throw new LogicException('unused');
    }
    public function deliverInbox(int $tenantId, string $outboxKey, string $jobKey): void
    {
        if ($this->inboxDeliveryFails) {
            throw new RuntimeException('database unavailable');
        }
    }
    public function beginSms(int $tenantId, string $outboxKey, string $jobKey): SmsDispatch
    {
        if ($this->beginFails) {
            throw new RuntimeException('database unavailable');
        }
        return new SmsDispatch($outboxKey, $tenantId, 501, $this->smsDigest, 'Security alert body', $jobKey, $this->receipt !== null);
    }
    public function reserveSmsRate(int $tenantId, string $recipientDigest): bool
    {
        return $this->rateAllowed;
    }
    public function completeSms(SmsDispatch $dispatch, SmsReceipt $receipt): void
    {
        $this->receipt = $receipt;
    }
    public function failSms(SmsDispatch $dispatch, string $safeCode, bool $retryable): void
    {
        if ($this->failureWriteFails) {
            throw new RuntimeException('database unavailable');
        }
        $this->failure = [$safeCode, $retryable];
    }
}

$renderer = new TemplateRenderer();
same('Hello Ada, code 42', $renderer->render('Hello {{name}}, code {{code}}', ['name', 'code'], ['name' => 'Ada', 'code' => 42], 100), 'strict render');
expectCode('NOTIFICATION_TEMPLATE_VARIABLE_INVALID', fn() => $renderer->render('{{name}}', ['name'], ['name' => 'Ada', 'extra' => 'x'], 100), 'unknown variable');
expectCode('NOTIFICATION_TEMPLATE_VARIABLE_INVALID', fn() => $renderer->render('{{name}}', ['name'], ['name' => '{{secret}}'], 100), 'recursive template injection');
expectCode('NOTIFICATION_TEMPLATE_VARIABLE_INVALID', fn() => $renderer->render('{{first}} {{second}}', ['first', 'second'], ['first' => '{{second}}', 'second' => 'secret'], 100), 'multi-variable recursive injection');

$recipient = new SmsRecipient('+8613800138000', str_repeat('k', 32));
same('+861*******000', $recipient->masked, 'phone mask');
if (str_contains(json_encode($recipient, JSON_THROW_ON_ERROR), '+8613800138000')) {
    throw new RuntimeException('raw phone serialized');
}
$provider = new LocalDevSmsProvider();
$request = new \PeanutAdmin\NotificationSms\Sms\SmsSendRequest('job_' . str_repeat('a', 32), 101, 'outbox_' . str_repeat('2', 32), '+8613800138000', 'Test body');
same('{}', json_encode($request, JSON_THROW_ON_ERROR), 'provider request is not implicitly serializable');
same($provider->send($request)->providerMessageKey, $provider->send($request)->providerMessageKey, 'provider idempotency');
same(1, $provider->acceptedCount(), 'provider deduplicates job key');

$repository = new MemoryRepository();
$service = new NotificationService(
    $repository,
    new class implements RecipientResolver {
        public function snapshot(TenantContext $context, int $memberId, bool $requiresSms): RecipientSnapshot
        {
            if ($context->tenantId !== 101 || $memberId !== 501 || !$requiresSms) {
                throw NotificationException::recipientUnavailable();
            }
            $phone = new SmsRecipient('+8613800138000', str_repeat('k', 32));
            return new RecipientSnapshot(501, 301, 'Ada Admin', $phone->masked, $phone->digest);
        }
    },
    new class implements AttachmentResolver {
        public function snapshot(TenantContext $context, string $fileKey): AttachmentReference
        {
            if ($context->tenantId !== 101 || $fileKey !== 'file_' . str_repeat('f', 32)) {
                throw NotificationException::attachmentUnavailable();
            }
            return new AttachmentReference($fileKey, 'report.pdf', 'application/pdf', 42, str_repeat('a', 64));
        }
    },
    $renderer,
);
$published = $service->publish(context('manage'), 'security.alert', [[
    'member_id' => 501, 'variables' => ['code' => 'A-42'],
]], ['file_' . str_repeat('f', 32)]);
same(1, count($published['messages']), 'one recipient message');
same(2, count($published['outbox']), 'channel outbox only');
same('Alert A-42', $published['messages'][0]->subject, 'template output');
expectCode('NOTIFICATION_PERMISSION_DENIED', fn() => $service->publish(context('read'), 'security.alert', [['member_id' => 501, 'variables' => ['code' => 'x']]]), 'permission boundary');
expectCode('NOTIFICATION_ATTACHMENT_UNAVAILABLE', fn() => $service->publish(context('manage'), 'security.alert', [['member_id' => 501, 'variables' => ['code' => 'x']]], ['file_' . str_repeat('e', 32)]), 'attachment tenant/ready boundary');
expectCode('NOTIFICATION_TEMPLATE_INVALID', fn() => $service->putTemplate(context('manage'), 'security.alert', 'Alert', '{{code}}', '{{code}}', ['inbox'], ['code', 'code'], null), 'duplicate template variables');
expectCode('NOTIFICATION_TEMPLATE_INVALID', fn() => $service->putTemplate(context('manage'), 'security.alert', 'Alert', '{{v0}}', '{{v0}}', ['inbox'], array_map(static fn(int $index): string => 'v' . $index, range(0, 32)), null), 'template variable bound');

$submission = (new OutboxTaskSubmissionProvider('sms'))->build(context('manage'), ['outbox_key' => 'outbox_' . str_repeat('2', 32)]);
same('notification.sms', $submission->handlerKey, 'trusted handler key');
same(['outbox_key' => 'outbox_' . str_repeat('2', 32)], $submission->payload, 'minimal trusted payload');

$resolver = new class implements SmsRecipientResolver {
    public function resolve(int $tenantId, int $memberId): SmsRecipient
    {
        if ($tenantId !== 101 || $memberId !== 501) {
            throw NotificationException::recipientUnavailable();
        }
        return new SmsRecipient('+8613800138000', str_repeat('k', 32));
    }
};
$handler = new SmsTaskHandler($repository, $resolver, $provider);
$execution = new JobExecution('job_' . str_repeat('b', 32), 101, 1, ['outbox_key' => 'outbox_' . str_repeat('2', 32)]);
$handler->handle(context('manage'), $execution);
same('DEV_ACCEPTED', $repository->receipt?->receiptCode, 'redacted dev receipt');
$handler->handle(context('manage'), $execution);

$limited = new MemoryRepository();
$limited->rateAllowed = false;
try {
    (new SmsTaskHandler($limited, $resolver, new LocalDevSmsProvider()))->handle(context('manage'), $execution);
    throw new RuntimeException('rate limit did not retry');
} catch (RetryableTaskException $exception) {
    same('SMS_RATE_LIMITED', $exception->safeCode, 'rate error class');
    same(['SMS_RATE_LIMITED', true], $limited->failure, 'rate failure persisted');
}

$transientLookup = new MemoryRepository();
$transientLookup->failureWriteFails = true;
try {
    (new SmsTaskHandler($transientLookup, new class implements SmsRecipientResolver {
        public function resolve(int $tenantId, int $memberId): SmsRecipient
        {
            throw new RuntimeException('private lookup detail');
        }
    }, new LocalDevSmsProvider()))->handle(context('manage'), $execution);
    throw new RuntimeException('transient recipient lookup did not retry');
} catch (RetryableTaskException $exception) {
    same('SMS_RECIPIENT_LOOKUP_FAILED', $exception->safeCode, 'lookup error class');
    same(null, $transientLookup->failure, 'persistence failure does not mask retry class');
}

$beginFailure = new MemoryRepository();
$beginFailure->beginFails = true;
try {
    (new SmsTaskHandler($beginFailure, $resolver, new LocalDevSmsProvider()))->handle(context('manage'), $execution);
    throw new RuntimeException('begin persistence failure did not retry');
} catch (RetryableTaskException $exception) {
    same('SMS_OUTBOX_PERSISTENCE_FAILED', $exception->safeCode, 'begin persistence classification');
}

$inboxFailure = new MemoryRepository();
$inboxFailure->inboxDeliveryFails = true;
try {
    (new InboxTaskHandler($inboxFailure))->handle(context('manage'), new JobExecution(
        'job_' . str_repeat('c', 32),
        101,
        1,
        ['outbox_key' => 'outbox_' . str_repeat('1', 32)],
    ));
    throw new RuntimeException('inbox persistence failure did not retry');
} catch (RetryableTaskException $exception) {
    same('NOTIFICATION_INBOX_PERSISTENCE_FAILED', $exception->safeCode, 'inbox persistence classification');
}

$permanentProvider = new MemoryRepository();
try {
    (new SmsTaskHandler($permanentProvider, $resolver, new class implements SmsProvider {
        public function key(): string
        {
            return 'test-provider';
        }
        public function send(\PeanutAdmin\NotificationSms\Sms\SmsSendRequest $request): SmsReceipt
        {
            throw SmsProviderException::permanent('SMS_DESTINATION_REJECTED');
        }
    }))->handle(context('manage'), $execution);
    throw new RuntimeException('permanent provider failure did not fail task');
} catch (NotificationException $exception) {
    same('SMS_DESTINATION_REJECTED', $exception->problemCode, 'permanent provider code');
    same(['SMS_DESTINATION_REJECTED', false], $permanentProvider->failure, 'permanent provider classification');
}

same(6, count(Schema::tableNames()), 'owned table count');
if (!class_exists(PdoNotificationRepository::class)) {
    throw new RuntimeException('PDO repository contract does not load');
}
$schema = implode("\n", array_map(Schema::createSql(...), Schema::tableNames()));
foreach (['tenant_id', 'recipient_phone_masked', 'recipient_phone_digest', 'dispatch_job_key', 'pa_sms_rate_bucket'] as $needle) {
    if (!str_contains($schema, $needle)) {
        throw new RuntimeException('schema missing ' . $needle);
    }
}
if (str_contains($schema, 'phone_e164') || str_contains($schema, 'provider_secret')) {
    throw new RuntimeException('schema stores raw phone or secret');
}

fwrite(STDOUT, "notification-sms feature harness: PASS\n");
