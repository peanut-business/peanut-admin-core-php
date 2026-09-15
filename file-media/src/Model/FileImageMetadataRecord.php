<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;
use think\model\relation\BelongsTo;

final class FileImageMetadataRecord extends TenantModel
{
    /** @var string */ protected $name = 'file_image_metadata';
    /** @var string */ protected $pk = 'file_object_id';

    /** @var array<string, string> */
    protected $type = [
        'tenant_id' => 'integer',
        'file_object_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function file(): BelongsTo
    {
        $relation = $this->belongsTo(FileObjectRecord::class, 'file_object_id', 'id');
        $relation->where('tenant_id', (int) $this->getAttr('tenant_id'));

        return $relation;
    }
}
