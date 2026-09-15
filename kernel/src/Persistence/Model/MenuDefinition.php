<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

use think\Model;

final class MenuDefinition extends Model
{
    /** @var string */ protected $name = 'menu_definition';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'sort_order' => 'integer',
    ];
}
