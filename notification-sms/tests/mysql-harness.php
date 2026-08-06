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

use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\NotificationSms\Application\AttachmentReference;
use PeanutAdmin\NotificationSms\Application\AttachmentResolver;
use PeanutAdmin\NotificationSms\Application\NotificationException;
use PeanutAdmin\NotificationSms\Application\NotificationService;
use PeanutAdmin\NotificationSms\Application\RecipientResolver;
use PeanutAdmin\NotificationSms\Application\RecipientSnapshot;
use PeanutAdmin\NotificationSms\Application\TemplateRenderer;
use PeanutAdmin\NotificationSms\Database\Schema;
use PeanutAdmin\NotificationSms\Package;
use PeanutAdmin\NotificationSms\Persistence\PdoNotificationRepository;
use PeanutAdmin\NotificationSms\Sms\SmsRecipient;
use PeanutAdmin\NotificationSms\Task\NotificationOutboxDispatcher;
use PeanutAdmin\NotificationSms\Task\OutboxTaskSubmissionProvider;
use PeanutAdmin\TaskJob\Database\Schema as TaskJobSchema;
use PeanutAdmin\TaskJob\Persistence\PdoTaskJobRepository;
use PeanutAdmin\TaskJob\Submission\TaskSubmissionRegistry;
use PeanutAdmin\TaskJob\Submission\TrustedJobPublisher;

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . var_export($actual, true));
    }
}

function operation(string $name, int $tenantId, int $accountId, int $memberId): AuthorizedOperationContext
{
    $session = new ValidatedTenantSession(
        $tenantId,
        'sess_' . str_pad((string) $tenantId, 32, '0'),
        $tenantId,
        $accountId,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2026-07-24T10:00:00Z'),
        1,
    );
    return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
        TenantContext::fromValidatedSession($session, 'req_b03_mysql_' . $tenantId),
        Package::RESOURCE_KEY,
        $name,
        [],
        hash('sha256', 'basis-' . $tenantId . '-' . $name),
    ));
}

$dsn = getenv('B03_MYSQL_DSN');
$user = getenv('B03_MYSQL_USER');
$password = getenv('B03_MYSQL_PASSWORD');
if (!is_string($dsn) || $dsn === '' || !is_string($user) || !is_string($password)) {
    throw new RuntimeException('B03 MySQL environment is incomplete.');
}
$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$drop = array_reverse(Schema::tableNames());
$taskDrop = array_reverse(TaskJobSchema::tableNames());
try {
    foreach ($drop as $table) {
        $pdo->exec(Schema::dropSql($table));
    }
    foreach ($taskDrop as $table) {
        $pdo->exec(TaskJobSchema::dropSql($table));
    }
    $pdo->exec('DROP TABLE IF EXISTS pa_tenant_member');
    $pdo->exec('DROP TABLE IF EXISTS pa_tenant');
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant (
  id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant_member (
  id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_tenant_member_tenant_id (tenant_id, id),
  CONSTRAINT fk_b03_member_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT
) ENGINE=InnoDB
SQL);
    $pdo->exec("INSERT INTO pa_tenant (id) VALUES (101), (202)");
    $pdo->exec("INSERT INTO pa_tenant_member (id, tenant_id, account_id, status) VALUES (501,101,301,'active'), (502,202,302,'active')");
    foreach (TaskJobSchema::tableNames() as $table) {
        $pdo->exec(TaskJobSchema::createSql($table));
    }
    foreach (Schema::tableNames() as $table) {
        $pdo->exec(Schema::createSql($table));
    }

    $repository = new PdoNotificationRepository($pdo);
    $digestKey = str_repeat('k', 32);
    $service = new NotificationService(
        $repository,
        new class ($digestKey) implements RecipientResolver {
            public function __construct(private readonly string $digestKey) {}
            public function snapshot(TenantContext $context, int $memberId, bool $requiresSms): RecipientSnapshot
            {
                $expected = $context->tenantId === 101 ? [501, 301, '+8613800138000'] : [502, 302, '+8613900139000'];
                if ($memberId !== $expected[0]) {
                    throw NotificationException::recipientUnavailable();
                }
                $sms = $requiresSms ? new SmsRecipient($expected[2], $this->digestKey) : null;
                return new RecipientSnapshot($expected[0], $expected[1], 'Tenant member', $sms?->masked, $sms?->digest);
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
        new TemplateRenderer(),
    );

    $manage101 = operation('manage', 101, 301, 501);
    $read101 = operation('read', 101, 301, 501);
    $read202 = operation('read', 202, 302, 502);
    $template = $service->putTemplate(
        $manage101,
        'security.alert',
        'Security alert',
        'Alert {{code}}',
        'Review {{code}}',
        ['inbox', 'sms'],
        ['code'],
        null,
    );
    same(1, $template['revision'], 'template revision');
    $created = $service->publish($manage101, 'security.alert', [[
        'member_id' => 501,
        'variables' => ['code' => 'A-42'],
    ]], ['file_' . str_repeat('f', 32)]);
    same(1, count($created['messages']), 'message count');
    same(2, count($created['outbox']), 'outbox count');
    same(1, $created['messages'][0]->templateRevision, 'message template revision snapshot');

    $inbox101 = $service->inbox($read101, 'all', 1, 20);
    $inbox202 = $service->inbox($read202, 'all', 1, 20);
    same(1, $inbox101['total'], 'own Tenant inbox');
    same(0, $inbox202['total'], 'cross-Tenant inbox isolation');
    $read = $service->markRead($read101, $inbox101['items'][0]->messageKey, 1);
    same('read', $read->status, 'read transition');
    same(1, $service->bulk($read101, [$read->messageKey], 'archive'), 'archive transition');

    $recipientDigest = (new SmsRecipient('+8613800138000', $digestKey))->digest;
    for ($attempt = 0; $attempt < 5; ++$attempt) {
        same(true, $repository->reserveSmsRate(101, $recipientDigest), 'recipient rate allowance');
    }
    same(false, $repository->reserveSmsRate(101, $recipientDigest), 'recipient rate bound');
    same(1, (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_template')->fetchColumn(), 'template row');
    same(1, (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_message WHERE template_revision = 1')->fetchColumn(), 'message row');

    $publisher = new TrustedJobPublisher(
        new PdoTaskJobRepository($pdo),
        new TaskSubmissionRegistry([
            new OutboxTaskSubmissionProvider('inbox'),
            new OutboxTaskSubmissionProvider('sms'),
        ]),
        new TrustedEnvelopeCodec(str_repeat('e', 32)),
    );
    $dispatcher = new NotificationOutboxDispatcher($repository, $publisher);
    $messageCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_message')->fetchColumn();
    $outboxCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_outbox')->fetchColumn();
    $notificationEventCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_event')->fetchColumn();
    $pdo->beginTransaction();
    $transactional = $service->publish($manage101, 'security.alert', [[
        'member_id' => 501,
        'variables' => ['code' => 'ROLLBACK'],
    ]], []);
    foreach ($transactional['outbox'] as $outbox) {
        $dispatcher->dispatch($manage101, $outbox->outboxKey);
    }
    same(2, (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_message')->fetchColumn(), 'outer transaction sees notification');
    same(2, (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn(), 'outer transaction sees dispatch jobs');
    same(2, (int) $pdo->query("SELECT COUNT(*) FROM pa_notification_outbox WHERE status = 'queued'")->fetchColumn(), 'outer transaction binds dispatch jobs');
    $pdo->rollBack();
    same($messageCount, (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_message')->fetchColumn(), 'outer rollback removes notification');
    same($outboxCount, (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_outbox')->fetchColumn(), 'outer rollback removes outbox rows');
    same(0, (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn(), 'outer rollback removes dispatch jobs');
    same(0, (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job_event')->fetchColumn(), 'outer rollback removes task events');
    same($notificationEventCount, (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_event')->fetchColumn(), 'outer rollback removes notification event');

    fwrite(STDOUT, "notification-sms MySQL harness: PASS\n");
} finally {
    foreach ($drop as $table) {
        $pdo->exec(Schema::dropSql($table));
    }
    foreach ($taskDrop as $table) {
        $pdo->exec(TaskJobSchema::dropSql($table));
    }
    $pdo->exec('DROP TABLE IF EXISTS pa_tenant_member');
    $pdo->exec('DROP TABLE IF EXISTS pa_tenant');
}
