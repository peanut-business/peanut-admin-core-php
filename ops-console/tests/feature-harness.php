<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach ([
        'PeanutAdmin\\OpsConsole\\' => $root . '/packages/php/ops-console/src/',
        'PeanutAdmin\\TaskJob\\' => $root . '/packages/php/task-job/src/',
        'PeanutAdmin\\Kernel\\' => $root . '/packages/php/kernel/src/',
    ] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
        }
    }
});

use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\OpsConsole\Application\OpsConsoleException;
use PeanutAdmin\OpsConsole\Application\PlatformPermissionChecker;
use PeanutAdmin\OpsConsole\Logs\RuntimeLogProvider;
use PeanutAdmin\OpsConsole\Logs\RuntimeLogProviderRegistry;
use PeanutAdmin\OpsConsole\Logs\RuntimeLogQuery;
use PeanutAdmin\OpsConsole\Logs\RuntimeLogService;
use PeanutAdmin\OpsConsole\Logs\SafeLogMessageCatalog;
use PeanutAdmin\OpsConsole\Logs\StructuredLogBatch;
use PeanutAdmin\OpsConsole\Logs\StructuredLogRecord;
use PeanutAdmin\OpsConsole\Maintenance\MaintenanceReasonRegistry;
use PeanutAdmin\OpsConsole\Maintenance\MaintenanceService;
use PeanutAdmin\OpsConsole\Maintenance\MaintenanceWindow;
use PeanutAdmin\OpsConsole\Maintenance\MaintenanceWindowStore;
use PeanutAdmin\OpsConsole\Package;
use PeanutAdmin\OpsConsole\Status\OpsStatusService;
use PeanutAdmin\OpsConsole\Status\OpsStatusSnapshot;
use PeanutAdmin\OpsConsole\Status\RuntimeStatusProvider;
use PeanutAdmin\OpsConsole\Task\BackupRestoreProvider;
use PeanutAdmin\OpsConsole\Task\BackupRestoreProviderRegistry;
use PeanutAdmin\OpsConsole\Task\OpsAuditEvent;
use PeanutAdmin\OpsConsole\Task\OpsTask;
use PeanutAdmin\OpsConsole\Task\OpsTaskDispatcher;
use PeanutAdmin\OpsConsole\Task\OpsTaskService;
use PeanutAdmin\OpsConsole\Task\OpsTaskSubmission;
use PeanutAdmin\OpsConsole\Task\TaskJobStatusProjection;
use PeanutAdmin\TaskJob\Application\JobRecord;

function same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function truth(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
}

function expectCode(string $code, callable $operation, string $label): void
{
    try {
        $operation();
    } catch (OpsConsoleException $exception) {
        same($code, $exception->problemCode, $label);
        truth(!str_contains($exception->getMessage(), 'password='), $label . ' redacts failure');
        return;
    }
    throw new RuntimeException($label . ' did not fail');
}

function context(): PlatformContext
{
    return PlatformContext::fromValidatedSession(new ValidatedPlatformSession(
        11, 'platform-session', 21, 31, 'platform-web',
        new DateTimeImmutable('2026-07-24T00:00:00Z'),
    ), 'req_ops_console_0001');
}

function task(int $number, string $type = Package::BACKUP_TASK_TYPE): OpsTask
{
    return new OpsTask(
        'job_' . str_pad(dechex($number), 32, '0', STR_PAD_LEFT), $type, 'queued',
        0, 3, 1, null, '2026-07-24T01:00:00.000Z', '2026-07-24T01:00:00.000Z',
        '2026-07-24T01:00:00.000Z', null,
    );
}

final class Permissions implements PlatformPermissionChecker
{
    /** @param list<string> $allowed */
    public function __construct(private array $allowed) {}
    public function allows(PlatformContext $context, string $permissionKey): bool
    {
        same(31, $context->operatorId, 'trusted platform context');
        return in_array($permissionKey, $this->allowed, true);
    }
}

final class StatusProvider implements RuntimeStatusProvider
{
    public function __construct(private bool $fail = false) {}
    public function snapshot(PlatformContext $context): OpsStatusSnapshot
    {
        if ($this->fail) throw new RuntimeException('mysql://root:password@host/database');
        return new OpsStatusSnapshot(
            'healthy', [['key' => 'database', 'status' => 'up', 'critical' => true, 'latency_ms' => 1.25]],
            str_repeat('a', 40), str_repeat('b', 40), 'starter-v1.stage-c', '2026-07-24T00:00:00.000Z',
            10, 12, 2, str_repeat('c', 64), false, 'ready', 'UPGRADE_PREFLIGHT_READY',
            str_repeat('d', 40), str_repeat('a', 40), true, true, true,
        );
    }
}

final class Provider implements BackupRestoreProvider
{
    public function __construct(private string $providerKey = 'reference.mysql', private array $targets = ['verification']) {}
    public function key(): string { return $this->providerKey; }
    public function backupHandlerKey(): string { return 'ops.backup.reference'; }
    public function restoreHandlerKey(): string { return 'ops.restore.reference'; }
    public function restoreTargetKeys(): array { return $this->targets; }
    public function maximumAttempts(): int { return 3; }
}

final class Dispatcher implements OpsTaskDispatcher
{
    /** @var array<string, array{request: string, task: OpsTask}> */
    public array $idempotency = [];
    /** @var array<string, OpsTask> */
    public array $tasks = [];
    /** @var array<string, true> */
    public array $active = [];
    /** @var list<OpsTaskSubmission> */
    public array $submissions = [];
    public bool $fail = false;

    public function dispatch(PlatformContext $context, OpsTaskSubmission $submission): OpsTask
    {
        if ($this->fail) throw new RuntimeException('password=do-not-leak /private/backup.sql');
        $existing = $this->idempotency[$submission->idempotencyDigest] ?? null;
        if ($existing !== null) {
            if (!hash_equals($existing['request'], $submission->requestDigest)) throw OpsConsoleException::idempotencyConflict();
            return $existing['task'];
        }
        if (isset($this->active[$submission->concurrencyKey])) throw OpsConsoleException::operationInProgress();
        $record = task(count($this->tasks) + 1, $submission->taskType);
        $this->submissions[] = $submission;
        $this->tasks[$record->taskKey] = $record;
        $this->idempotency[$submission->idempotencyDigest] = ['request' => $submission->requestDigest, 'task' => $record];
        $this->active[$submission->concurrencyKey] = true;
        return $record;
    }

    public function find(PlatformContext $context, string $taskKey): OpsTask
    {
        return $this->tasks[$taskKey] ?? throw OpsConsoleException::taskNotFound();
    }
}

final class MaintenanceStore implements MaintenanceWindowStore
{
    public ?MaintenanceWindow $window = null;
    /** @var array<string, array{request: string, window: MaintenanceWindow}> */
    public array $idempotency = [];
    /** @var list<OpsAuditEvent> */
    public array $audits = [];

    public function current(PlatformContext $context): ?MaintenanceWindow { return $this->window; }

    public function schedule(PlatformContext $context, MaintenanceWindow $candidate, int $expectedRevision, string $idempotencyDigest, string $requestDigest, OpsAuditEvent $audit): MaintenanceWindow
    {
        $replay = $this->replay($idempotencyDigest, $requestDigest);
        if ($replay !== null) return $replay;
        $actual = $this->window?->revision ?? 0;
        if ($actual !== $expectedRevision || ($this->window !== null && $this->window->state !== 'closed' && $expectedRevision === 0)) {
            throw OpsConsoleException::revisionConflict();
        }
        $this->window = $candidate;
        $this->idempotency[$idempotencyDigest] = ['request' => $requestDigest, 'window' => $candidate];
        $this->audits[] = $audit;
        return $candidate;
    }

    public function close(PlatformContext $context, string $maintenanceKey, int $expectedRevision, string $idempotencyDigest, string $requestDigest, OpsAuditEvent $audit): MaintenanceWindow
    {
        $replay = $this->replay($idempotencyDigest, $requestDigest);
        if ($replay !== null) return $replay;
        if ($this->window === null || $this->window->maintenanceKey !== $maintenanceKey || $this->window->revision !== $expectedRevision) {
            throw OpsConsoleException::revisionConflict();
        }
        $this->window = new MaintenanceWindow(
            $this->window->maintenanceKey, 'closed', $this->window->reasonKey,
            $this->window->startsAt, $this->window->endsAt, $expectedRevision + 1,
        );
        $this->idempotency[$idempotencyDigest] = ['request' => $requestDigest, 'window' => $this->window];
        $this->audits[] = $audit;
        return $this->window;
    }

    private function replay(string $key, string $request): ?MaintenanceWindow
    {
        $existing = $this->idempotency[$key] ?? null;
        if ($existing === null) return null;
        if (!hash_equals($existing['request'], $request)) throw OpsConsoleException::idempotencyConflict();
        return $existing['window'];
    }
}

final class LogProvider implements RuntimeLogProvider
{
    public function __construct(private bool $fail = false) {}
    public function sourceKey(): string { return 'application'; }
    public function read(PlatformContext $context, RuntimeLogQuery $query): StructuredLogBatch
    {
        if ($this->fail) throw new RuntimeException('Stack trace #0 /private/app.php password=secret');
        return new StructuredLogBatch([
            new StructuredLogRecord('runtime.request.failed', 'error', 'http.runtime', '2026-07-24T02:00:00.000Z', 'req_ops_console_0002', 2),
            new StructuredLogRecord('runtime.unknown', 'warning', 'worker.runtime', '2026-07-24T02:01:00.000Z', null, 1),
        ], 'cursor_12345678');
    }
}

$platform = context();
$all = new Permissions([
    Package::READ_PERMISSION, Package::BACKUP_PERMISSION, Package::RESTORE_PERMISSION,
    Package::MAINTENANCE_PERMISSION, Package::LOGS_PERMISSION,
]);
$none = new Permissions([]);

$status = (new OpsStatusService($all, new StatusProvider()))->read($platform)->toPublicArray();
same('healthy', $status['health']['status'], 'health evidence');
same(2, $status['migrations']['pending'], 'migration evidence');
truth(!str_contains(json_encode($status, JSON_THROW_ON_ERROR), '/'), 'status has no path');
expectCode('OPS_PERMISSION_DENIED', fn() => (new OpsStatusService($none, new StatusProvider()))->read($platform), 'status permission');
expectCode('OPS_STATUS_UNAVAILABLE', fn() => (new OpsStatusService($all, new StatusProvider(true)))->read($platform), 'status failure');

try {
    new BackupRestoreProviderRegistry([new Provider(targets: ['production'])]);
    throw new RuntimeException('unsafe target registration did not fail');
} catch (InvalidArgumentException) {}

$dispatcher = new Dispatcher();
$tasks = new OpsTaskService($all, new BackupRestoreProviderRegistry([new Provider()]), $dispatcher);
$backup = $tasks->submitBackup($platform, 'reference.mysql', 'backup-request-0001');
$replay = $tasks->submitBackup($platform, 'reference.mysql', 'backup-request-0001');
same($backup->taskKey, $replay->taskKey, 'backup exact replay');
same(['provider_key'], array_keys($dispatcher->submissions[0]->payload), 'backup payload is fixed');
truth(!str_contains(json_encode($dispatcher->submissions[0]->payload, JSON_THROW_ON_ERROR), 'command'), 'no command payload');
expectCode('OPS_OPERATION_IN_PROGRESS', fn() => $tasks->submitBackup($platform, 'reference.mysql', 'backup-request-0002'), 'backup concurrency');
expectCode('OPS_PERMISSION_DENIED', fn() => (new OpsTaskService($none, new BackupRestoreProviderRegistry([new Provider()]), new Dispatcher()))->submitBackup($platform, 'reference.mysql', 'backup-request-0003'), 'backup permission');
expectCode('OPS_RESTORE_TARGET_INVALID', fn() => $tasks->submitRestore($platform, 'reference.mysql', 'backup_12345678', 'production', 'restore-request-0001'), 'restore target allowlist');

$restoreDispatcher = new Dispatcher();
$restoreTasks = new OpsTaskService($all, new BackupRestoreProviderRegistry([new Provider()]), $restoreDispatcher);
$restore = $restoreTasks->submitRestore($platform, 'reference.mysql', 'backup_12345678', 'verification', 'restore-request-0002');
same(Package::RESTORE_TASK_TYPE, $restore->taskType, 'restore task type');
same(['provider_key', 'backup_reference_key', 'target_key'], array_keys($restoreDispatcher->submissions[0]->payload), 'restore payload is fixed');
same('verification', $restoreDispatcher->submissions[0]->payload['target_key'], 'restore target is registered');

$failingDispatcher = new Dispatcher();
$failingDispatcher->fail = true;
expectCode('OPS_PROVIDER_UNAVAILABLE', fn() => (new OpsTaskService($all, new BackupRestoreProviderRegistry([new Provider()]), $failingDispatcher))->submitBackup($platform, 'reference.mysql', 'backup-request-0004'), 'provider failure');

$job = new JobRecord(1, 'job_' . str_repeat('a', 32), 999, Package::BACKUP_TASK_TYPE, 'queued', 0, 3, 1, null, '2026-07-24T00:00:00.000Z', '2026-07-24T00:00:00.000Z', '2026-07-24T00:00:00.000Z', null);
same(Package::BACKUP_TASK_TYPE, TaskJobStatusProjection::fromRecord($job)->taskType, 'TaskJob status projection');
$tenantJob = new JobRecord(2, 'job_' . str_repeat('b', 32), 1, 'tenant.export', 'queued', 0, 3, 1, null, '2026-07-24T00:00:00.000Z', '2026-07-24T00:00:00.000Z', '2026-07-24T00:00:00.000Z', null);
expectCode('OPS_TASK_NOT_FOUND', fn() => TaskJobStatusProjection::fromRecord($tenantJob), 'Tenant task does not cross audience');

$maintenanceStore = new MaintenanceStore();
$maintenance = new MaintenanceService($all, new MaintenanceReasonRegistry(['upgrade', 'restore-verification']), $maintenanceStore);
$window = $maintenance->schedule($platform, 'upgrade', '2026-07-24T03:00:00.000Z', '2026-07-24T04:00:00.000Z', 0, 'maintenance-request-0001');
$windowReplay = $maintenance->schedule($platform, 'upgrade', '2026-07-24T03:00:00.000Z', '2026-07-24T04:00:00.000Z', 0, 'maintenance-request-0001');
same($window->maintenanceKey, $windowReplay->maintenanceKey, 'maintenance exact replay');
expectCode('OPS_IDEMPOTENCY_CONFLICT', fn() => $maintenance->schedule($platform, 'upgrade', '2026-07-24T03:00:00.000Z', '2026-07-24T05:00:00.000Z', 0, 'maintenance-request-0001'), 'maintenance idempotency conflict');
expectCode('OPS_REVISION_CONFLICT', fn() => $maintenance->close($platform, $window->maintenanceKey, 2, 'maintenance-close-0001'), 'maintenance stale revision');
$closed = $maintenance->close($platform, $window->maintenanceKey, 1, 'maintenance-close-0002');
same('closed', $closed->state, 'maintenance close');
same(2, count($maintenanceStore->audits), 'maintenance audits commit with writes');
expectCode('OPS_MAINTENANCE_INVALID', fn() => $maintenance->schedule($platform, 'upgrade', '2026-07-24T00:00:00.000Z', '2026-07-26T00:00:00.000Z', 2, 'maintenance-request-0002'), 'maintenance duration');

try {
    new SafeLogMessageCatalog(['runtime.request.failed' => 'password=secret']);
    throw new RuntimeException('unsafe catalog message did not fail');
} catch (InvalidArgumentException) {}
$catalog = new SafeLogMessageCatalog(['runtime.request.failed' => 'A runtime request failed.']);
$logs = new RuntimeLogService($all, new RuntimeLogProviderRegistry([new LogProvider()]), $catalog);
$page = $logs->read($platform, new RuntimeLogQuery('application', 'warning', null, 20))->toPublicArray();
same('A runtime request failed.', $page['items'][0]['message'], 'known safe log message');
same('An operational event occurred.', $page['items'][1]['message'], 'unknown log message is generic');
$encodedLogs = json_encode($page, JSON_THROW_ON_ERROR);
truth(!preg_match('/password=|Stack trace|\/private\/|mysql:/i', $encodedLogs), 'logs contain no raw evidence');
expectCode('OPS_PERMISSION_DENIED', fn() => (new RuntimeLogService($none, new RuntimeLogProviderRegistry([new LogProvider()]), $catalog))->read($platform, new RuntimeLogQuery('application', 'info', null, 20)), 'logs permission');
expectCode('OPS_LOGS_UNAVAILABLE', fn() => (new RuntimeLogService($all, new RuntimeLogProviderRegistry([new LogProvider(true)]), $catalog))->read($platform, new RuntimeLogQuery('application', 'info', null, 20)), 'logs provider failure');

echo "Ops Console PHP feature: OK\n";
