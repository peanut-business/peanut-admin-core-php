<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Persistence\Model;

use think\Model;

final class ReferenceCodeEntryVersionRecord extends Model
{
    /** @var string */ protected $name = 'reference_code_entry_version';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'entry_id' => 'integer', 'revision' => 'integer',
    ];
}
