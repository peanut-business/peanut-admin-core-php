<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class IntegrationWebhookEndpointRecord extends TenantModel
{
    /** @var string */ protected $name = 'integration_webhook_endpoint';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'created_by_member_id' => 'integer', 'revision' => 'integer',
    ];
}
