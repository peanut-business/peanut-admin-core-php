<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Idempotency\Model;

use think\Model;

final class PlatformIdempotencyRecord extends Model
{
    /** @var string */ protected $name = 'platform_idempotency_record';
    /** @var bool */ protected $autoWriteTimestamp = false;

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'platform_operator_id' => 'integer',
        'response_status' => 'integer',
    ];
}
