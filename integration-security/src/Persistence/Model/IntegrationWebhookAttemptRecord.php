<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class IntegrationWebhookAttemptRecord extends TenantModel
{
    /** @var string */ protected $name = 'integration_webhook_attempt';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'delivery_id' => 'integer',
        'attempt_number' => 'integer', 'response_status' => 'integer', 'duration_ms' => 'integer',
    ];
}
