<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Policy;

final readonly class EffectivePolicySet
{
    /** @param list<EffectiveConditionGroup> $groups */
    public function __construct(
        public array $groups,
        public ?int $primaryDepartmentId,
    ) {}

    /** @return list<int> */
    public function policyIds(): array
    {
        $ids = [];
        foreach ($this->groups as $group) {
            $ids[$group->policyId] = true;
        }

        return array_map('intval', array_keys($ids));
    }
}
