<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class IntegrationMachineIdentityRecord extends TenantModel
{
    /** @var string */ protected $name = 'integration_machine_identity';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'created_by_member_id' => 'integer', 'revision' => 'integer',
    ];
}
