<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Governance;

final readonly class RoleDataPolicyChange
{
    /** @param list<string> $conditionKeys */
    public function __construct(
        public int $roleId,
        public int $expectedRevision,
        public string $resourceKey,
        public string $operation,
        public array $conditionKeys,
    ) {}
}
