<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Model;

use think\Model;

final class ResourceOperationRecord extends Model
{
    /** @var string */ protected $name = 'resource_operation';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'protected_resource_id' => 'integer',
    ];
}
