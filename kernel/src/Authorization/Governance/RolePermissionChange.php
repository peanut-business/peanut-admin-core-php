<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Governance;

final readonly class RolePermissionChange
{
    /** @param list<string> $permissionKeys */
    public function __construct(
        public string $audience,
        public int $roleId,
        public int $expectedRevision,
        public array $permissionKeys,
    ) {}
}
