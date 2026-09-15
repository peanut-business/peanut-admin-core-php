<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Persistence\Model;

use think\Model;

final class ReferenceCodeSetRecord extends Model
{
    /** @var string */ protected $name = 'reference_code_set';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'revision' => 'integer',
        'created_at' => 'string',
        'updated_at' => 'string',
    ];
}
