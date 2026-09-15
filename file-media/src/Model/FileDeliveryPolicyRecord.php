<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class FileDeliveryPolicyRecord extends TenantModel
{
    /** @var string */ protected $name = 'file_delivery_policy';
    /** @var string */ protected $pk = 'file_object_id';

    /** @var array<string, string> */
    protected $type = [
        'tenant_id' => 'integer',
        'file_object_id' => 'integer',
        'revision' => 'integer',
    ];
}
