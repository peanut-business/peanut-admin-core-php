<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Policy;

final readonly class EffectiveConditionGroup
{
    /** @param non-empty-list<EffectiveCondition> $conditions */
    public function __construct(
        public int $policyId,
        public int $roleId,
        public int $groupId,
        public array $conditions,
    ) {}
}
