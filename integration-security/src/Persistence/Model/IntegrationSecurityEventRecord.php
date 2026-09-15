<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class IntegrationSecurityEventRecord extends TenantModel
{
    /** @var string */ protected $name = 'integration_security_event';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'actor_member_id' => 'integer',
    ];
}
