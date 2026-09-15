<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Model;

use PeanutAdmin\Kernel\Persistence\Model\EditionTenantModel;
use think\model\relation\BelongsTo;

final class TenantSettingValue extends EditionTenantModel
{
    /** @var string */ protected $name = 'setting_tenant_value';

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'definition_id' => 'integer',
        'revision' => 'integer',
        'updated_by_member_id' => 'integer',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(SettingDefinitionRecord::class, 'definition_id', 'id');
    }
}
