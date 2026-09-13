<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Runtime;

use think\db\PDOConnection;

interface DataPermissionModuleProvider
{
    public function registerDataPermission(DataPermissionRuntimeRegistry $registry, PDOConnection $connection): void;
}
