<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

final class TenantSession extends TenantModel
{
    /** @var string */ protected $name = 'tenant_session';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'account_id' => 'integer',
        'tenant_member_id' => 'integer',
    ];
}
