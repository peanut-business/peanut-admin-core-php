<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use PeanutAdmin\Workflow\Application\WorkflowException;

final readonly class WorkflowNotificationIntent
{
    public function __construct(
        public string $templateKey,
        public string $recipientRule,
    ) {
        if (strlen($templateKey) < 1
            || strlen($templateKey) > 64
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $templateKey) !== 1
            || !in_array($recipientRule, ['next_assignees', 'initiator', 'actor'], true)) {
            throw WorkflowException::definitionInvalid();
        }
    }

    /** @return array{template_key: string, recipient_rule: string} */
    public function toArray(): array
    {
        return ['template_key' => $this->templateKey, 'recipient_rule' => $this->recipientRule];
    }
}
