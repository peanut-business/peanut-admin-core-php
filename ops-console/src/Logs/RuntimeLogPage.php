<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Logs;

final readonly class RuntimeLogPage
{
    /** @param list<RuntimeLogEntry> $items */
    public function __construct(public array $items, public ?string $nextCursor) {}

    /** @return array{items: list<array<string, int|string|null>>, next_cursor: string|null} */
    public function toPublicArray(): array
    {
        return [
            'items' => array_map(static fn(RuntimeLogEntry $entry): array => $entry->toPublicArray(), $this->items),
            'next_cursor' => $this->nextCursor,
        ];
    }
}
