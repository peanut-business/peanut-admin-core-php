<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

use InvalidArgumentException;

final readonly class RequestedTargetSet
{
    /** @var non-empty-list<string> */
    public array $targetIds;

    /** @param list<string> $targetIds */
    public function __construct(
        public string $targetResourceKey,
        array $targetIds,
        public string $targetRole = 'primary',
    ) {
        if ($targetResourceKey === '' || $targetRole === '' || $targetIds === []) {
            throw new InvalidArgumentException('Typed target set cannot be empty.');
        }
        $normalized = array_values(array_unique(array_map('strval', $targetIds), SORT_STRING));
        sort($normalized, SORT_STRING);
        $this->targetIds = array_map('strval', $normalized);
    }

    /** @return array{target_resource_key: string, target_role: string, target_ids: non-empty-list<string>} */
    public function toArray(): array
    {
        return [
            'target_resource_key' => $this->targetResourceKey,
            'target_role' => $this->targetRole,
            'target_ids' => $this->targetIds,
        ];
    }
}
