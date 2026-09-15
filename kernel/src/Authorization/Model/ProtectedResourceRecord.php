<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Model;

use think\Model;

final class ProtectedResourceRecord extends Model
{
    /** @var string */ protected $name = 'protected_resource';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = ['id' => 'integer'];
}
