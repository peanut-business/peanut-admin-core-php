<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

final class AuthorizationSqlTrace
{
    /** @var list<array{sql: string, parameters: array<string, int|string>}> */
    private array $entries = [];

    /** @param array<string, int|string> $parameters */
    public function record(string $sql, array $parameters): void
    {
        $this->entries[] = ['sql' => $sql, 'parameters' => $parameters];
    }

    /** @return list<array{sql: string, parameters: array<string, int|string>}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function lastSql(): string
    {
        return $this->entries[array_key_last($this->entries)]['sql'] ?? '';
    }
}
