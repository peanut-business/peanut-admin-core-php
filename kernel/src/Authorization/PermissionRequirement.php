<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use InvalidArgumentException;

final readonly class PermissionRequirement
{
    /** @param list<string> $permissionKeys */
    public function __construct(
        public string $audience,
        public array $permissionKeys,
        public string $match = 'all',
    ) {
        if (!in_array($audience, ['tenant', 'platform'], true)) {
            throw new InvalidArgumentException('Permission audience must be tenant or platform.');
        }
        if ($permissionKeys === []) {
            throw new InvalidArgumentException('At least one permission is required.');
        }
        if (!in_array($match, ['all', 'any'], true)) {
            throw new InvalidArgumentException('Permission match must be all or any.');
        }
    }
}
