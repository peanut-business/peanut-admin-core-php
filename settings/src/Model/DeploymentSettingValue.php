<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Model;

use think\Model;
use think\model\relation\BelongsTo;

final class DeploymentSettingValue extends Model
{
    /** @var string */ protected $name = 'setting_deployment_value';
    /** @var bool */ protected $autoWriteTimestamp = false;

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'definition_id' => 'integer',
        'value_json' => 'string',
        'revision' => 'integer',
        'updated_by_operator_id' => 'integer',
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
