<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

use InvalidArgumentException;

final readonly class ColumnReference
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $value) !== 1) {
            throw new InvalidArgumentException('Column references must be trusted identifiers.');
        }
    }
}
