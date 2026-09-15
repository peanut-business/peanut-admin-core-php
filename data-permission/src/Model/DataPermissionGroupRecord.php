<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class DataPermissionGroupRecord extends TenantModel
{
    /** @var string */ protected $name = 'data_permission_group';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'data_permission_policy_id' => 'integer',
    ];
}
