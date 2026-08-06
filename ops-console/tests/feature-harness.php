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
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\OpsConsole\Application\OpsConsoleException;
use PeanutAdmin\OpsConsole\Application\PlatformPermissionChecker;
use PeanutAdmin\OpsConsole\Logs\LogSeverity;
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
    if (!$condition) {
        throw new RuntimeException($label);
    }
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

function expectInvalidArgument(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($label . ' did not fail');
}

function context(): PlatformContext
{
    return PlatformContext::fromValidatedSession(new ValidatedPlatformSession(
        11,
        'platform-session',
        21,
        31,
        'platform-web',
        new DateTimeImmutable('2026-07-24T00:00:00Z'),
    ), 'req_ops_console_0001');
}

function task(int $number, string $type = Package::BACKUP_TASK_TYPE): OpsTask
{
    return new OpsTask(
        'job_' . str_pad(dechex($number), 32, '0', STR_PAD_LEFT),
        $type,
        'queued',
        0,
        3,
        1,
        null,
        '2026-07-24T01:00:00.000Z',
        '2026-07-24T01:00:00.000Z',
        '2026-07-24T01:00:00.000Z',
        null,
    );
}

/** @param array<string, mixed> $overrides */
function statusSnapshot(array $overrides = []): OpsStatusSnapshot
{
    return new OpsStatusSnapshot(...array_merge([
        'health' => 'healthy',
        'checks' => [['key' => 'database', 'status' => 'up', 'critical' => true, 'latency_ms' => 1.25]],
        'commit' => str_repeat('a', 40),
        'tree' => str_repeat('b', 40),
        'releaseKey' => 'starter-v1.stage-c',
        'builtAt' => '2026-07-24T00:00:00.000Z',
        'appliedMigrations' => 12,
        'targetMigrations' => 12,
        'pendingMigrations' => 0,
        'migrationDigest' => str_repeat('c', 64),
        'migrationDrift' => false,
        'upgradeState' => 'ready',
        'upgradeCode' => 'UPGRADE_PREFLIGHT_READY',
        'sourceCommit' => str_repeat('d', 40),
        'targetCommit' => str_repeat('a', 40),
        'repositoryClean' => true,
        'backupVerified' => true,
        'sourceEvidenceMatches' => true,
    ], $overrides));
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
        if ($this->fail) {
            throw new RuntimeException('mysql://root:password@host/database');
        }
        return statusSnapshot();
    }
}

final class Provider implements BackupRestoreProvider
{
    public function __construct(private string $providerKey = 'reference.mysql', private array $targets = ['verification']) {}
    public function key(): string
    {
        return $this->providerKey;
    }
    public function backupHandlerKey(): string
    {
        return 'ops.backup.reference';
    }
    public function restoreHandlerKey(): string
    {
        return 'ops.restore.reference';
    }
    public function restoreTargetKeys(): array
    {
        return $this->targets;
    }
    public function maximumAttempts(): int
    {
        return 3;
    }
}

final class MutableProvider implements BackupRestoreProvider
{
    /** @var array<string, int> */
    public array $calls = ['key' => 0, 'backup' => 0, 'restore' => 0, 'targets' => 0, 'attempts' => 0];
    public string $providerKey = 'snapshot.mysql';
    public string $backupHandler = 'ops.backup.snapshot';
    public string $restoreHandler = 'ops.restore.snapshot';
    /** @var list<string> */
    public array $targets = ['verification'];
    public int $attempts = 3;

    public function key(): string
    {
        ++$this->calls['key'];
        return $this->providerKey;
    }
    public function backupHandlerKey(): string
    {
        ++$this->calls['backup'];
        return $this->backupHandler;
    }
    public function restoreHandlerKey(): string
    {
        ++$this->calls['restore'];
        return $this->restoreHandler;
    }
    public function restoreTargetKeys(): array
    {
        ++$this->calls['targets'];
        return $this->targets;
    }
    public function maximumAttempts(): int
    {
        ++$this->calls['attempts'];
        return $this->attempts;
    }
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
        if ($this->fail) {
            throw new RuntimeException('password=do-not-leak /private/backup.sql');
        }
        $existing = $this->idempotency[$submission->idempotencyDigest] ?? null;
        if ($existing !== null) {
            if (!hash_equals($existing['request'], $submission->requestDigest)) {
                throw OpsConsoleException::idempotencyConflict();
            }
            return $existing['task'];
        }
        if (isset($this->active[$submission->concurrencyKey])) {
            throw OpsConsoleException::operationInProgress();
        }
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
    public ?MaintenanceWindow $returnOverride = null;
    /** @var array<string, array{request: string, window: MaintenanceWindow}> */
    public array $idempotency = [];
    /** @var list<OpsAuditEvent> */
    public array $audits = [];

    public function current(PlatformContext $context): ?MaintenanceWindow
    {
        return $this->window;
    }

    public function schedule(PlatformContext $context, MaintenanceWindow $candidate, int $expectedRevision, string $idempotencyDigest, string $requestDigest, OpsAuditEvent $audit): MaintenanceWindow
    {
        $replay = $this->replay($idempotencyDigest, $requestDigest);
        if ($replay !== null) {
            return $this->returned($replay);
        }
        $actual = $this->window?->revision ?? 0;
        if ($actual !== $expectedRevision || ($this->window !== null && $this->window->state !== 'closed' && $expectedRevision === 0)) {
            throw OpsConsoleException::revisionConflict();
        }
        $this->window = $candidate;
        $this->idempotency[$idempotencyDigest] = ['request' => $requestDigest, 'window' => $candidate];
        $this->audits[] = $audit;
        return $this->returned($candidate);
    }

    public function close(PlatformContext $context, string $maintenanceKey, int $expectedRevision, string $idempotencyDigest, string $requestDigest, OpsAuditEvent $audit): MaintenanceWindow
    {
        $replay = $this->replay($idempotencyDigest, $requestDigest);
        if ($replay !== null) {
            return $replay;
        }
        if ($this->window === null || $this->window->maintenanceKey !== $maintenanceKey || $this->window->revision !== $expectedRevision) {
            throw OpsConsoleException::revisionConflict();
        }
        $this->window = new MaintenanceWindow(
            $this->window->maintenanceKey,
            'closed',
            $this->window->reasonKey,
            $this->window->startsAt,
            $this->window->endsAt,
            $expectedRevision + 1,
        );
        $this->idempotency[$idempotencyDigest] = ['request' => $requestDigest, 'window' => $this->window];
        $this->audits[] = $audit;
        return $this->returned($this->window);
    }

    private function replay(string $key, string $request): ?MaintenanceWindow
    {
        $existing = $this->idempotency[$key] ?? null;
        if ($existing === null) {
            return null;
        }
        if (!hash_equals($existing['request'], $request)) {
            throw OpsConsoleException::idempotencyConflict();
        }
        return $existing['window'];
    }

    private function returned(MaintenanceWindow $window): MaintenanceWindow
    {
        return $this->returnOverride ?? $window;
    }
}

final class LogProvider implements RuntimeLogProvider
{
    public function __construct(private bool $fail = false, private ?StructuredLogBatch $batch = null) {}
    public function sourceKey(): string
    {
        return 'application';
    }
    public function read(PlatformContext $context, RuntimeLogQuery $query): StructuredLogBatch
    {
        if ($this->fail) {
            throw new RuntimeException('Stack trace #0 /private/app.php password=secret');
        }
        return $this->batch ?? new StructuredLogBatch([
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
same(0, $status['migrations']['pending'], 'migration evidence');
truth(!str_contains(json_encode($status, JSON_THROW_ON_ERROR), '/'), 'status has no path');
expectCode('OPS_PERMISSION_DENIED', fn() => (new OpsStatusService($none, new StatusProvider()))->read($platform), 'status permission');
expectCode('OPS_STATUS_UNAVAILABLE', fn() => (new OpsStatusService($all, new StatusProvider(true)))->read($platform), 'status failure');
expectInvalidArgument(fn() => statusSnapshot([
    'checks' => [['key' => 'database', 'status' => 'down', 'critical' => true, 'latency_ms' => 1.25]],
]), 'healthy status with critical check down');
expectInvalidArgument(fn() => statusSnapshot(['migrationDrift' => true]), 'healthy status with migration drift');
expectInvalidArgument(fn() => statusSnapshot([
    'appliedMigrations' => 11, 'targetMigrations' => 12, 'pendingMigrations' => 1,
]), 'healthy status with pending migrations');
$integerLatencyStatus = statusSnapshot(['checks' => [[
    'latency_ms' => 1, 'critical' => true, 'status' => 'up', 'key' => 'database',
]]]);
same(1, $integerLatencyStatus->checks[0]['latency_ms'], 'health check keys and integer latency match JSON semantics');
expectInvalidArgument(fn() => statusSnapshot(['checks' => [[
    'key' => 'database', 'status' => 'up', 'critical' => true, 'latency_ms' => 1, 'unexpected' => false,
]]]), 'health check exact key set');
foreach (['repositoryClean', 'backupVerified', 'sourceEvidenceMatches'] as $evidence) {
    expectInvalidArgument(fn() => statusSnapshot(['upgradeState' => 'succeeded', $evidence => false]), 'succeeded upgrade without ' . $evidence);
}

try {
    new BackupRestoreProviderRegistry([new Provider(targets: ['production'])]);
    throw new RuntimeException('unsafe target registration did not fail');
} catch (InvalidArgumentException) {
}

$mutableProvider = new MutableProvider();
$snapshotRegistry = new BackupRestoreProviderRegistry([$mutableProvider]);
same(['key' => 1, 'backup' => 1, 'restore' => 1, 'targets' => 1, 'attempts' => 1], $mutableProvider->calls, 'provider metadata snapshotted once');
same($mutableProvider, $snapshotRegistry->require('snapshot.mysql')->providerReference(), 'opaque provider reference retained');
$mutableProvider->providerKey = 'changed.mysql';
$mutableProvider->backupHandler = 'ops.backup.changed';
$mutableProvider->restoreHandler = 'ops.restore.changed';
$mutableProvider->targets = ['production'];
$mutableProvider->attempts = 10;
$snapshotDispatcher = new Dispatcher();
$snapshotTasks = new OpsTaskService($all, $snapshotRegistry, $snapshotDispatcher);
$snapshotTasks->submitBackup($platform, 'snapshot.mysql', 'snapshot-backup-0001');
$snapshotTasks->submitRestore($platform, 'snapshot.mysql', 'backup_12345678', 'verification', 'snapshot-restore-0001');
same('snapshot.mysql', $snapshotDispatcher->submissions[0]->payload['provider_key'], 'snapshotted provider key');
same('ops.backup.snapshot', $snapshotDispatcher->submissions[0]->handlerKey, 'snapshotted backup handler');
same('ops.restore.snapshot', $snapshotDispatcher->submissions[1]->handlerKey, 'snapshotted restore handler');
same(3, $snapshotDispatcher->submissions[0]->maximumAttempts, 'snapshotted maximum attempts');
expectCode('OPS_PROVIDER_NOT_FOUND', fn() => $snapshotTasks->submitBackup($platform, 'changed.mysql', 'snapshot-backup-0002'), 'mutated provider key unavailable');
expectCode('OPS_RESTORE_TARGET_INVALID', fn() => $snapshotTasks->submitRestore($platform, 'snapshot.mysql', 'backup_12345678', 'production', 'snapshot-restore-0002'), 'mutated unsafe target unavailable');
same(['key' => 1, 'backup' => 1, 'restore' => 1, 'targets' => 1, 'attempts' => 1], $mutableProvider->calls, 'task service uses only provider snapshot');

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
expectInvalidArgument(fn() => task(3, 'ops.arbitrary'), 'OpsTask exact type allowlist');
$arbitraryJob = new JobRecord(3, 'job_' . str_repeat('c', 32), 999, 'ops.arbitrary', 'queued', 0, 3, 1, null, '2026-07-24T00:00:00.000Z', '2026-07-24T00:00:00.000Z', '2026-07-24T00:00:00.000Z', null);
expectCode('OPS_TASK_NOT_FOUND', fn() => TaskJobStatusProjection::fromRecord($arbitraryJob), 'TaskJob projection exact type allowlist');

$maintenanceStore = new MaintenanceStore();
$maintenance = new MaintenanceService($all, new MaintenanceReasonRegistry(['upgrade', 'restore-verification']), $maintenanceStore);
$window = $maintenance->schedule($platform, 'upgrade', '2026-07-24T03:00:00.000Z', '2026-07-24T04:00:00.000Z', 0, 'maintenance-request-0001');
$windowReplay = $maintenance->schedule($platform, 'upgrade', '2026-07-24T03:00:00.000Z', '2026-07-24T04:00:00.000Z', 0, 'maintenance-request-0001');
same($window->maintenanceKey, $windowReplay->maintenanceKey, 'maintenance exact replay');
truth($window !== $maintenanceStore->window && $windowReplay !== $maintenanceStore->window, 'maintenance schedule and replay are reconstructed');
$currentWindow = $maintenance->current($platform);
truth($currentWindow !== $window && $currentWindow?->maintenanceKey === $window->maintenanceKey, 'maintenance current is reconstructed');
$serializedWindow = serialize($window);
$forgedWindow = unserialize(str_replace('T04:00:00.000Z', 'T02:00:00.000Z', $serializedWindow), ['allowed_classes' => [MaintenanceWindow::class]]);
truth($forgedWindow instanceof MaintenanceWindow, 'forged maintenance fixture');
$maintenanceStore->window = $forgedWindow;
expectCode('OPS_INTERNAL_ERROR', fn() => $maintenance->current($platform), 'invalid stored maintenance fails closed');
$maintenanceStore->window = $window;
expectCode('OPS_IDEMPOTENCY_CONFLICT', fn() => $maintenance->schedule($platform, 'upgrade', '2026-07-24T03:00:00.000Z', '2026-07-24T05:00:00.000Z', 0, 'maintenance-request-0001'), 'maintenance idempotency conflict');
expectCode('OPS_REVISION_CONFLICT', fn() => $maintenance->close($platform, $window->maintenanceKey, 2, 'maintenance-close-0001'), 'maintenance stale revision');
$closed = $maintenance->close($platform, $window->maintenanceKey, 1, 'maintenance-close-0002');
same('closed', $closed->state, 'maintenance close');
truth($closed !== $maintenanceStore->window, 'maintenance close is reconstructed');
same(2, count($maintenanceStore->audits), 'maintenance audits commit with writes');
expectCode('OPS_MAINTENANCE_INVALID', fn() => $maintenance->schedule($platform, 'upgrade', '2026-07-24T00:00:00.000Z', '2026-07-26T00:00:00.000Z', 2, 'maintenance-request-0002'), 'maintenance duration');
expectInvalidArgument(fn() => new MaintenanceWindow('maintenance_' . str_repeat('3', 32), 'scheduled', 'upgrade', '2026-07-24T03:00:00.000Z', '2026-07-24T03:00:00.000Z', 1), 'maintenance requires positive duration');
expectInvalidArgument(fn() => new MaintenanceWindow('maintenance_' . str_repeat('4', 32), 'scheduled', 'upgrade', '2026-07-24T03:00:00.000Z', '2026-07-25T03:00:00.001Z', 1), 'maintenance rejects 24 hours plus one millisecond');

$invalidScheduleStore = new MaintenanceStore();
$invalidScheduleStore->returnOverride = $forgedWindow;
expectCode('OPS_INTERNAL_ERROR', fn() => (new MaintenanceService($all, new MaintenanceReasonRegistry(['upgrade']), $invalidScheduleStore))->schedule(
    $platform,
    'upgrade',
    '2026-07-24T05:00:00.000Z',
    '2026-07-24T06:00:00.000Z',
    0,
    'invalid-schedule-return-0001',
), 'invalid schedule store return fails closed');
$invalidReplayStore = new MaintenanceStore();
$invalidReplayService = new MaintenanceService($all, new MaintenanceReasonRegistry(['upgrade']), $invalidReplayStore);
$invalidReplayService->schedule($platform, 'upgrade', '2026-07-24T05:00:00.000Z', '2026-07-24T06:00:00.000Z', 0, 'invalid-replay-return-0001');
$invalidReplayStore->returnOverride = $forgedWindow;
expectCode('OPS_INTERNAL_ERROR', fn() => $invalidReplayService->schedule(
    $platform,
    'upgrade',
    '2026-07-24T05:00:00.000Z',
    '2026-07-24T06:00:00.000Z',
    0,
    'invalid-replay-return-0001',
), 'invalid idempotent replay store return fails closed');
$invalidCloseStore = new MaintenanceStore();
$invalidCloseService = new MaintenanceService($all, new MaintenanceReasonRegistry(['upgrade']), $invalidCloseStore);
$closeCandidate = $invalidCloseService->schedule($platform, 'upgrade', '2026-07-24T05:00:00.000Z', '2026-07-24T06:00:00.000Z', 0, 'invalid-close-setup-0001');
$invalidCloseStore->returnOverride = $forgedWindow;
expectCode('OPS_INTERNAL_ERROR', fn() => $invalidCloseService->close(
    $platform,
    $closeCandidate->maintenanceKey,
    $closeCandidate->revision,
    'invalid-close-return-0001',
), 'invalid close store return fails closed');

try {
    new SafeLogMessageCatalog(['runtime.request.failed' => 'password=secret']);
    throw new RuntimeException('unsafe catalog message did not fail');
} catch (InvalidArgumentException) {
}
$catalog = new SafeLogMessageCatalog(['runtime.request.failed' => 'A runtime request failed.']);
same(['info', 'warning', 'error', 'critical'], LogSeverity::VALUES, 'PHP log severity parity');
expectInvalidArgument(fn() => new RuntimeLogQuery('application', 'debug', null, 20), 'debug query severity is rejected');
expectInvalidArgument(fn() => new StructuredLogRecord('runtime.request.failed', 'debug', 'http.runtime', '2026-07-24T02:00:00.000Z', null, 1), 'debug record severity is rejected');
$logs = new RuntimeLogService($all, new RuntimeLogProviderRegistry([new LogProvider()]), $catalog);
$page = $logs->read($platform, new RuntimeLogQuery('application', 'warning', null, 20))->toPublicArray();
same('A runtime request failed.', $page['items'][0]['message'], 'known safe log message');
same('An operational event occurred.', $page['items'][1]['message'], 'unknown log message is generic');
$encodedLogs = json_encode($page, JSON_THROW_ON_ERROR);
truth(!preg_match('/password=|Stack trace|\/private\/|mysql:/i', $encodedLogs), 'logs contain no raw evidence');
expectCode('OPS_PERMISSION_DENIED', fn() => (new RuntimeLogService($none, new RuntimeLogProviderRegistry([new LogProvider()]), $catalog))->read($platform, new RuntimeLogQuery('application', 'info', null, 20)), 'logs permission');
expectCode('OPS_LOGS_UNAVAILABLE', fn() => (new RuntimeLogService($all, new RuntimeLogProviderRegistry([new LogProvider(true)]), $catalog))->read($platform, new RuntimeLogQuery('application', 'info', null, 20)), 'logs provider failure');
$oneRecord = new StructuredLogRecord('runtime.request.failed', 'warning', 'http.runtime', '2026-07-24T02:00:00.000Z', null, 1);
expectCode('OPS_LOGS_UNAVAILABLE', fn() => (new RuntimeLogService($all, new RuntimeLogProviderRegistry([
    new LogProvider(batch: new StructuredLogBatch([$oneRecord, $oneRecord], null)),
]), $catalog))->read($platform, new RuntimeLogQuery('application', 'info', null, 1)), 'logs provider exceeds requested page size');
expectCode('OPS_LOGS_UNAVAILABLE', fn() => (new RuntimeLogService($all, new RuntimeLogProviderRegistry([
    new LogProvider(batch: new StructuredLogBatch([$oneRecord], null)),
]), $catalog))->read($platform, new RuntimeLogQuery('application', 'error', null, 20)), 'logs provider violates minimum severity');
expectCode('OPS_LOGS_UNAVAILABLE', fn() => (new RuntimeLogService($all, new RuntimeLogProviderRegistry([
    new LogProvider(batch: new StructuredLogBatch([$oneRecord], 'cursor_12345678')),
]), $catalog))->read($platform, new RuntimeLogQuery('application', 'info', 'cursor_12345678', 20)), 'logs provider repeats input cursor');

echo "Ops Console PHP feature: OK\n";
