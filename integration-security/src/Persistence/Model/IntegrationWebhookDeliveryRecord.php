<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class IntegrationWebhookDeliveryRecord extends TenantModel
{
    /** @var string */ protected $name = 'integration_webhook_delivery';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'endpoint_id' => 'integer', 'attempt_count' => 'integer',
    ];
}
