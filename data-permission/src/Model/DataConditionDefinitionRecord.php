<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Model;

use think\Model;

final class DataConditionDefinitionRecord extends Model
{
    /** @var string */ protected $name = 'data_condition_definition';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = ['id' => 'integer'];
}
