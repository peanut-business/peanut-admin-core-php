<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Target;

use InvalidArgumentException;

final readonly class TypedResourceTargetCollection
{
    /** @param list<TypedResourceTargetSet> $sets */
    public function __construct(public array $sets = [])
    {
        $seen = [];
        foreach ($sets as $set) {
            $key = $set->targetRole . ':' . $set->targetResourceKey;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('A typed target set cannot be repeated.');
            }
            $seen[$key] = true;
        }
    }

    public function countForRole(string $role): int
    {
        $count = 0;
        foreach ($this->sets as $set) {
            if ($set->targetRole === $role) {
                $count += count($set->targetIds);
            }
        }

        return $count;
    }
}
