<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

use think\Model;

final class LoginChallenge extends Model
{
    /** @var string */ protected $name = 'login_challenge';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = ['id' => 'integer', 'account_id' => 'integer'];
}
