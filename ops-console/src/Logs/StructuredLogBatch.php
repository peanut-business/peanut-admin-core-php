<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Logs;

use InvalidArgumentException;
use PeanutAdmin\OpsConsole\Support\Contract;

final readonly class StructuredLogBatch
{
    /** @param list<StructuredLogRecord> $records */
    public function __construct(public array $records, public ?string $nextCursor)
    {
        if (count($records) > 100) {
            throw new InvalidArgumentException('Too many log records.');
        }
        foreach ($records as $record) {
            if (!$record instanceof StructuredLogRecord) {
                throw new InvalidArgumentException('Invalid log record list.');
            }
        }
        if ($nextCursor !== null) {
            Contract::opaqueKey($nextCursor, 'cursor_');
        }
    }
}
