<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Logs;

use InvalidArgumentException;

final class LogSeverity
{
    public const VALUES = ['info', 'warning', 'error', 'critical'];

    private const RANKS = ['info' => 0, 'warning' => 1, 'error' => 2, 'critical' => 3];

    public static function rank(string $severity): int
    {
        return self::RANKS[$severity] ?? throw new InvalidArgumentException('Invalid log severity.');
    }

    private function __construct() {}
}
