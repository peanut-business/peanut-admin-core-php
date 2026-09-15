<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

final class TenantEntryBinding extends TenantModel
{
    /** @var string */ protected $name = 'tenant_entry_binding';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
    ];
}
