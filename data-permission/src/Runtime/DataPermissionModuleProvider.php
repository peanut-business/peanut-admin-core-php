<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Runtime;

interface DataPermissionModuleProvider
{
    public function registerDataPermission(DataPermissionRuntimeRegistry $registry): void;
}
