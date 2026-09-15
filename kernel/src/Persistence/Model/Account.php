<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

use think\Model;

final class Account extends Model
{
    /** @var string */ protected $name = 'account';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'security_revision' => 'integer',
    ];
}
