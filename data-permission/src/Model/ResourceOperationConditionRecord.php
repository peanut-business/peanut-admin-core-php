<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Model;

use think\Model;

final class ResourceOperationConditionRecord extends Model
{
    /** @var string */ protected $name = 'resource_operation_condition';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = ['id' => 'integer'];
}
