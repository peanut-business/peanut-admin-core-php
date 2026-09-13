<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface WorkflowAttachmentResolver
{
    public function snapshot(AuthorizedOperationContext $context, string $fileKey): WorkflowAttachment;
}
