<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Model;

use think\Model;

final class ResourceOperationPermissionRecord extends Model
{
    /** @var string */ protected $name = 'resource_operation_permission';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = ['id' => 'integer'];
}
