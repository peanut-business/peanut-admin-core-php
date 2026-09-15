<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Model;

use PeanutAdmin\Kernel\Persistence\Model\EditionTenantModel;
use think\model\relation\BelongsTo;

final class TargetSettingValue extends EditionTenantModel
{
    /** @var string */ protected $name = 'setting_target_value';

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'definition_id' => 'integer',
        'value_json' => 'string',
        'revision' => 'integer',
        'updated_by_member_id' => 'integer',
        'effective_at' => 'string',
        'expires_at' => 'string',
        'created_at' => 'string',
        'updated_at' => 'string',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(SettingDefinitionRecord::class, 'definition_id', 'id');
    }
}
