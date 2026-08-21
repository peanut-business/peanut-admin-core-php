<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Support;

use DateTimeImmutable;
use InvalidArgumentException;

final class Contract
{
    public static function qualifiedKey(string $value, int $maximum = 96): string
    {
        if ($value === '' || strlen($value) > $maximum
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('Invalid qualified key.');
        }
        return $value;
    }

    public static function opaqueKey(string $value, string $prefix, int $maximum = 128): string
    {
        if (strlen($value) > $maximum
            || preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9][A-Za-z0-9._:-]{7,}$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('Invalid opaque key.');
        }
        return $value;
    }

    public static function hash(string $value): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid digest.');
        }
        return $value;
    }

    public static function commit(?string $value): ?string
    {
        if ($value !== null && preg_match('/^[0-9a-f]{40}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid commit identity.');
        }
        return $value;
    }

    public static function instant(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid UTC instant.');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value);
        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d\TH:i:s.v\Z') !== $value) {
            throw new InvalidArgumentException('Invalid UTC instant.');
        }
        return $value;
    }

    public static function stableCode(string $value): string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid stable code.');
        }
        return $value;
    }

    public static function publicText(string $value, int $maximum = 160): string
    {
        if ($value === '' || strlen($value) > $maximum || preg_match('/[\x00-\x1f\x7f]/D', $value) === 1
            || preg_match('/(?:password|passwd|secret|token|credential|dsn)\s*[:=]/i', $value) === 1
            || preg_match('~(?:mysql|pgsql|postgres|redis|https?)://[^\s]*@~i', $value) === 1
            || preg_match('~(?:^|\s)(?:/[^\s]+|[A-Za-z]:\\\\[^\s]+)~', $value) === 1
            || preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE|DROP|ALTER|CREATE)\s+/i', $value) === 1
            || preg_match('/(?:stack trace|^#\d+\s)/im', $value) === 1
        ) {
            throw new InvalidArgumentException('Unsafe public text.');
        }
        return $value;
    }

    private function __construct() {}
}
