<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

use think\Model;

final class PlatformSessionToken extends Model
{
    /** @var string */ protected $name = 'platform_session_token';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'session_id' => 'integer',
    ];
}
