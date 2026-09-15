<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class WorkflowWorkItemRecord extends TenantModel
{
    /** @var string */ protected $name = 'workflow_work_item';
    /** @var string */ protected $dateFormat = 'Y-m-d H:i:s.v';
}
