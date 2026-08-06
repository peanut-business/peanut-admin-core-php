<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Logs;

use InvalidArgumentException;
use PeanutAdmin\OpsConsole\Support\Contract;

final readonly class SafeLogMessageCatalog
{
    /** @param array<string, string> $messages */
    public function __construct(private array $messages)
    {
        if (count($messages) > 256) {
            throw new InvalidArgumentException('Too many safe log messages.');
        }
        foreach ($messages as $key => $message) {
            Contract::qualifiedKey($key, 96);
            Contract::publicText($message);
        }
    }

    public function message(string $eventKey): string
    {
        return $this->messages[$eventKey] ?? 'An operational event occurred.';
    }
}
