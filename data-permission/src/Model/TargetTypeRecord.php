<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Model;

use think\Model;

final class TargetTypeRecord extends Model
{
    /** @var string */ protected $name = 'target_type';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = ['id' => 'integer'];
}
