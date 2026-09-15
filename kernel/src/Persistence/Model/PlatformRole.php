<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

use think\Model;

final class PlatformRole extends Model
{
    /** @var string */ protected $name = 'platform_role';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'is_builtin' => 'boolean',
        'revision' => 'integer',
    ];
}
