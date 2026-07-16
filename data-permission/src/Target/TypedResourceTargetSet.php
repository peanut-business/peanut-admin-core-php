<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Target;

use InvalidArgumentException;

final readonly class TypedResourceTargetSet
{
    /** @var list<string> */
    public array $targetIds;

    /** @param list<string> $targetIds */
    public function __construct(
        public string $targetResourceKey,
        array $targetIds,
        public string $targetRole = 'primary',
    ) {
        if ($targetResourceKey === '' || $targetRole === '') {
            throw new InvalidArgumentException('Target resource key and role are required.');
        }
        $normalized = [];
        foreach ($targetIds as $targetId) {
            $targetId = trim($targetId);
            if ($targetId === '') {
                throw new InvalidArgumentException('Target IDs cannot be empty.');
            }
            $normalized[] = $targetId;
        }
        $this->targetIds = array_values(array_unique($normalized, SORT_STRING));
    }
}
