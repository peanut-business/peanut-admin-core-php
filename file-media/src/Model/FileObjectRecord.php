<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;
use think\model\relation\HasOne;

final class FileObjectRecord extends TenantModel
{
    /** @var string */ protected $name = 'file_object';

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'size_bytes' => 'integer',
        'created_by_member_id' => 'integer',
        'revision' => 'integer',
    ];

    public function imageMetadata(): HasOne
    {
        $relation = $this->hasOne(FileImageMetadataRecord::class, 'file_object_id', 'id');
        $relation->where('tenant_id', (int) $this->getAttr('tenant_id'));

        return $relation;
    }

    public function deliveryPolicy(): HasOne
    {
        $relation = $this->hasOne(FileDeliveryPolicyRecord::class, 'file_object_id', 'id');
        $relation->where('tenant_id', (int) $this->getAttr('tenant_id'));

        return $relation;
    }
}
