<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Task;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\OpsConsole\Application\OpsConsoleException;
use PeanutAdmin\OpsConsole\Application\PlatformPermissionChecker;
use PeanutAdmin\OpsConsole\Package;
use PeanutAdmin\OpsConsole\Support\Contract;
use Throwable;

final readonly class OpsTaskService
{
    public function __construct(
        private PlatformPermissionChecker $permissions,
        private BackupRestoreProviderRegistry $providers,
        private OpsTaskDispatcher $dispatcher,
    ) {}

    public function submitBackup(PlatformContext $context, string $providerKey, string $idempotencyKey): OpsTask
    {
        $this->assertAllowed($context, Package::BACKUP_PERMISSION);
        $provider = $this->providers->require($providerKey);
        return $this->dispatch($context, $provider, Package::BACKUP_TASK_TYPE, [
            'provider_key' => $provider->key,
        ], $provider->backupHandlerKey, $idempotencyKey, 'platform.ops.backup.submitted', 'backup.submit');
    }

    public function submitRestore(
        PlatformContext $context,
        string $providerKey,
        string $backupReferenceKey,
        string $targetKey,
        string $idempotencyKey,
    ): OpsTask {
        $this->assertAllowed($context, Package::RESTORE_PERMISSION);
        $provider = $this->providers->require($providerKey);
        try {
            Contract::opaqueKey($backupReferenceKey, 'backup_');
            Contract::qualifiedKey($targetKey, 64);
        } catch (InvalidArgumentException) {
            throw OpsConsoleException::restoreTargetInvalid();
        }
        if (!in_array($targetKey, $provider->restoreTargetKeys, true)) {
            throw OpsConsoleException::restoreTargetInvalid();
        }
        return $this->dispatch($context, $provider, Package::RESTORE_TASK_TYPE, [
            'provider_key' => $provider->key,
            'backup_reference_key' => $backupReferenceKey,
            'target_key' => $targetKey,
        ], $provider->restoreHandlerKey, $idempotencyKey, 'platform.ops.restore.submitted', 'restore.submit');
    }

    public function task(PlatformContext $context, string $taskKey): OpsTask
    {
        $this->assertAllowed($context, Package::READ_PERMISSION);
        try {
            Contract::opaqueKey($taskKey, 'job_');
            return $this->dispatcher->find($context, $taskKey);
        } catch (OpsConsoleException $exception) {
            throw $exception;
        } catch (InvalidArgumentException) {
            throw OpsConsoleException::taskNotFound();
        } catch (Throwable) {
            throw OpsConsoleException::taskUnavailable();
        }
    }

    /** @param array<string, string> $payload */
    private function dispatch(
        PlatformContext $context,
        BackupRestoreProviderDescriptor $provider,
        string $taskType,
        array $payload,
        string $handlerKey,
        string $idempotencyKey,
        string $eventType,
        string $action,
    ): OpsTask {
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 200
            || preg_match('/^[\x21-\x7e]+$/D', $idempotencyKey) !== 1
        ) {
            throw OpsConsoleException::invalid();
        }
        $idempotencyDigest = hash('sha256', $idempotencyKey);
        $requestDigest = hash('sha256', json_encode(
            ['task_type' => $taskType, 'payload' => $payload],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        $audit = new OpsAuditEvent($eventType, $action, array_filter([
            'provider_key' => $provider->key,
            'target_key' => $payload['target_key'] ?? null,
            'idempotency_digest' => $idempotencyDigest,
            'request_digest' => $requestDigest,
        ], static fn(mixed $value): bool => $value !== null));
        try {
            return $this->dispatcher->dispatch($context, new OpsTaskSubmission(
                $taskType,
                $handlerKey,
                $payload,
                $idempotencyDigest,
                $requestDigest,
                $taskType . '.' . $provider->key,
                $provider->maximumAttempts,
                $audit,
            ));
        } catch (OpsConsoleException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw OpsConsoleException::providerUnavailable();
        }
    }

    private function assertAllowed(PlatformContext $context, string $permission): void
    {
        if (!$this->permissions->allows($context, $permission)) {
            throw OpsConsoleException::denied();
        }
    }
}
