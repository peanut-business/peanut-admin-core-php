<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

final class Department extends TenantModel
{
    /** @var string */ protected $name = 'department';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'parent_id' => 'integer',
        'revision' => 'integer',
    ];
}
