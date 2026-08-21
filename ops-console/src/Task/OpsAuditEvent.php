<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Task;

use InvalidArgumentException;
use PeanutAdmin\OpsConsole\Support\Contract;

final readonly class OpsAuditEvent
{
    private const METADATA_KEYS = [
        'provider_key', 'task_key', 'target_key', 'maintenance_key',
        'revision', 'idempotency_digest', 'request_digest',
    ];

    /** @param array<string, bool|int|string|null> $metadata */
    public function __construct(
        public string $eventType,
        public string $action,
        public array $metadata,
    ) {
        Contract::qualifiedKey($eventType, 96);
        Contract::qualifiedKey($action, 64);
        foreach ($metadata as $key => $value) {
            if (!in_array($key, self::METADATA_KEYS, true) || (!is_bool($value) && !is_int($value) && !is_string($value) && $value !== null)) {
                throw new InvalidArgumentException('Unsafe audit metadata.');
            }
            if (is_string($value)) {
                match ($key) {
                    'idempotency_digest', 'request_digest' => Contract::hash($value),
                    'task_key' => Contract::opaqueKey($value, 'job_'),
                    'maintenance_key' => Contract::opaqueKey($value, 'maintenance_'),
                    default => Contract::qualifiedKey($value, 128),
                };
            }
        }
    }
}
