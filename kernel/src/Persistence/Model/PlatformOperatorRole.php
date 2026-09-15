<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

use think\Model;

final class PlatformOperatorRole extends Model
{
    /** @var string */ protected $name = 'platform_operator_role';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'platform_operator_id' => 'integer',
        'platform_role_id' => 'integer',
    ];
}
