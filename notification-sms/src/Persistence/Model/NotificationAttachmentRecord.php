<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class NotificationAttachmentRecord extends TenantModel
{
    /** @var string */ protected $name = 'notification_attachment';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'message_id' => 'integer', 'size_bytes' => 'integer',
    ];
}
