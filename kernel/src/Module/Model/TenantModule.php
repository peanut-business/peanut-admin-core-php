<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class TenantModule extends TenantModel
{
    /** @var string */ protected $name = 'tenant_module';

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'config_json' => 'json',
        'config_revision' => 'integer',
        'authorization_revision' => 'integer',
        'effective_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
