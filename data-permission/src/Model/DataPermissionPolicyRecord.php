<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class DataPermissionPolicyRecord extends TenantModel
{
    /** @var string */ protected $name = 'data_permission_policy';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'role_id' => 'integer',
        'protected_resource_id' => 'integer', 'resource_operation_id' => 'integer', 'revision' => 'integer',
    ];
}
