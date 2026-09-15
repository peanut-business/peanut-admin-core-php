<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

final class TenantMember extends TenantModel
{
    /** @var string */ protected $name = 'tenant_member';

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'account_id' => 'integer',
    ];
}
