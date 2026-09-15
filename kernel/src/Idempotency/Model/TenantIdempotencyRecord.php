<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Idempotency\Model;

use PeanutAdmin\Kernel\Persistence\Model\EditionTenantModel;

final class TenantIdempotencyRecord extends EditionTenantModel
{
    /** @var string */ protected $name = 'tenant_idempotency_record';

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'tenant_member_id' => 'integer',
        'response_status' => 'integer',
    ];
}
