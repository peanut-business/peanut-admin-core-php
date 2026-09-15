<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Model;

final class MemberRole extends TenantModel
{
    /** @var string */ protected $name = 'member_role';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'tenant_member_id' => 'integer',
        'role_id' => 'integer',
    ];
}
