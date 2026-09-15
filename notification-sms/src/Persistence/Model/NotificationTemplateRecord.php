<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class NotificationTemplateRecord extends TenantModel
{
    /** @var string */ protected $name = 'notification_template';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'created_by_member_id' => 'integer', 'revision' => 'integer',
        'channels_json' => 'string', 'variable_keys_json' => 'string',
    ];
}
