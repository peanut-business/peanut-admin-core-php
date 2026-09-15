<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class DataPermissionTargetRecord extends TenantModel
{
    /** @var string */ protected $name = 'data_permission_target';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'target_set_id' => 'integer',
    ];
}
