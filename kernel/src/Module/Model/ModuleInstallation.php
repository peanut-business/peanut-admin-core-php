<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module\Model;

use think\Model;

final class ModuleInstallation extends Model
{
    /** @var string */ protected $name = 'module_installation';
    /** @var bool */ protected $autoWriteTimestamp = false;

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'manifest_schema_version' => 'integer',
        'revision' => 'integer',
    ];
}
