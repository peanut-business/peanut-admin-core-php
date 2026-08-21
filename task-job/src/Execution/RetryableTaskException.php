<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Execution;

use RuntimeException;

final class RetryableTaskException extends RuntimeException
{
    public function __construct(public readonly string $safeCode = 'TASK_HANDLER_RETRY')
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $safeCode) !== 1) {
            throw new RuntimeException('Invalid retry error code.');
        }
        parent::__construct($safeCode);
    }
}
