<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class NotificationMessageRecord extends TenantModel
{
    /** @var string */ protected $name = 'notification_message';
    /** @var string */ protected $dateFormat = 'Y-m-d H:i:s.v';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'template_revision' => 'integer',
        'recipient_member_id' => 'integer', 'recipient_account_id' => 'integer',
        'created_by_member_id' => 'integer', 'revision' => 'integer',
    ];
}
