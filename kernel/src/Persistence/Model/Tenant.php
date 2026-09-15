<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

use think\Model;

final class Tenant extends Model
{
    /** @var string */ protected $name = 'tenant';
    /** @var bool */ protected $autoWriteTimestamp = false;

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'revision' => 'integer',
        'authorization_revision' => 'integer',
    ];
}
