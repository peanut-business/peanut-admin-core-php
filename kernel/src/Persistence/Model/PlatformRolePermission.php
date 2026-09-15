<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

use think\Model;

final class PlatformRolePermission extends Model
{
    /** @var string */ protected $name = 'platform_role_permission';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'platform_role_id' => 'integer',
        'platform_permission_id' => 'integer',
    ];
}
