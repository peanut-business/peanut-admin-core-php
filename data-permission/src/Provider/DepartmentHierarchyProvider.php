<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

interface DepartmentHierarchyProvider
{
    /** @return list<int> */
    public function descendantsIncludingSelf(int $tenantId, int $departmentId): array;
}
