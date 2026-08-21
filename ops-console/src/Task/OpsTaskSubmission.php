<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Task;

use InvalidArgumentException;
use PeanutAdmin\OpsConsole\Package;
use PeanutAdmin\OpsConsole\Support\Contract;

final readonly class OpsTaskSubmission
{
    /** @param array<string, string> $payload */
    public function __construct(
        public string $taskType,
        public string $handlerKey,
        public array $payload,
        public string $idempotencyDigest,
        public string $requestDigest,
        public string $concurrencyKey,
        public int $maximumAttempts,
        public OpsAuditEvent $audit,
    ) {
        Contract::qualifiedKey($handlerKey);
        Contract::hash($idempotencyDigest);
        Contract::hash($requestDigest);
        Contract::qualifiedKey($concurrencyKey, 128);
        if ($maximumAttempts < 1 || $maximumAttempts > 10) {
            throw new InvalidArgumentException('Invalid task attempts.');
        }
        $expected = match ($taskType) {
            Package::BACKUP_TASK_TYPE => ['provider_key'],
            Package::RESTORE_TASK_TYPE => ['provider_key', 'backup_reference_key', 'target_key'],
            default => throw new InvalidArgumentException('Invalid operations task type.'),
        };
        if (array_keys($payload) !== $expected) {
            throw new InvalidArgumentException('Invalid trusted task payload.');
        }
        Contract::qualifiedKey($payload['provider_key']);
        if ($taskType === Package::RESTORE_TASK_TYPE) {
            Contract::opaqueKey($payload['backup_reference_key'], 'backup_');
            Contract::qualifiedKey($payload['target_key'], 64);
        }
    }
}
