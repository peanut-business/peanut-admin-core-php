<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

final class RolePermission extends TenantModel
{
    /** @var string */ protected $name = 'role_permission';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'role_id' => 'integer',
        'permission_id' => 'integer',
    ];
}
