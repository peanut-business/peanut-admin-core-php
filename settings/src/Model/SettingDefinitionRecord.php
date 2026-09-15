<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Model;

use think\Model;
use think\model\relation\HasOne;

final class SettingDefinitionRecord extends Model
{
    /** @var string */ protected $name = 'setting_definition';
    /** @var bool */ protected $autoWriteTimestamp = false;

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'schema_json' => 'string',
        'default_json' => 'string',
        'required_flag' => 'integer',
        'secret_flag' => 'integer',
        'deployment_scope_flag' => 'integer',
        'tenant_scope_flag' => 'integer',
        'target_scope_flag' => 'integer',
        'revision' => 'integer',
        'created_at' => 'string',
        'updated_at' => 'string',
    ];

    public function deploymentValue(): HasOne
    {
        return $this->hasOne(DeploymentSettingValue::class, 'definition_id', 'id');
    }
}
