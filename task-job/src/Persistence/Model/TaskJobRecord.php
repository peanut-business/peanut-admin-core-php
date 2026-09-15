<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class TaskJobRecord extends TenantModel
{
    /** @var string */ protected $name = 'task_job';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'created_by_member_id' => 'integer',
        'priority' => 'integer', 'attempt_count' => 'integer', 'max_attempts' => 'integer', 'revision' => 'integer',
    ];
}
