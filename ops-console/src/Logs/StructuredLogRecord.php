<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Logs;

use InvalidArgumentException;
use PeanutAdmin\OpsConsole\Support\Contract;

final readonly class StructuredLogRecord
{
    public function __construct(
        public string $eventKey,
        public string $severity,
        public string $componentKey,
        public string $occurredAt,
        public ?string $requestId,
        public int $occurrences,
    ) {
        Contract::qualifiedKey($eventKey, 96);
        Contract::qualifiedKey($componentKey, 64);
        Contract::instant($occurredAt);
        if ($requestId !== null) {
            Contract::opaqueKey($requestId, 'req_', 128);
        }
        if (!in_array($severity, LogSeverity::VALUES, true)
            || $occurrences < 1 || $occurrences > 1000000
        ) {
            throw new InvalidArgumentException('Invalid structured log record.');
        }
    }
}
