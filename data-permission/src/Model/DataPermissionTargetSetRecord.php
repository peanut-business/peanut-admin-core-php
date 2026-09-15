<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class DataPermissionTargetSetRecord extends TenantModel
{
    /** @var string */ protected $name = 'data_permission_target_set';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'revision' => 'integer',
    ];
}
