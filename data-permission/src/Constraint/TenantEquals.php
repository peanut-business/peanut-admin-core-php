<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

final readonly class TenantEquals implements QueryConstraint
{
    public function __construct(public ColumnReference $column, public int $tenantId) {}
}
