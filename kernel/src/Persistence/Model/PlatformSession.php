<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

use think\Model;

final class PlatformSession extends Model
{
    /** @var string */ protected $name = 'platform_session';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'account_id' => 'integer',
        'platform_operator_id' => 'integer',
    ];
}
