<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use PDO;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface WorkflowAttachmentResolver
{
    public function connection(): PDO;

    public function snapshot(AuthorizedOperationContext $context, string $fileKey): WorkflowAttachment;
}
