<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

use think\Model;

final class AuthSecurityEvent extends Model
{
    /** @var string */ protected $name = 'auth_security_event';
    /** @var bool */ protected $autoWriteTimestamp = false;
}
