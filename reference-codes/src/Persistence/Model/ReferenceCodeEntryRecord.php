<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class ReferenceCodeEntryRecord extends TenantModel
{
    /** @var string */ protected $name = 'reference_code_entry';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'set_id' => 'integer', 'revision' => 'integer',
    ];
}
