<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Runtime;

use PDO;

interface DataPermissionModuleProvider
{
    public function registerDataPermission(DataPermissionRuntimeRegistry $registry, PDO $pdo): void;
}
