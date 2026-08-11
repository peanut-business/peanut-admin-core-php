<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Definition;

final readonly class WorkflowTransition
{
    /**
     * @param non-empty-list<string> $permissionKeys
     * @param array{template_key: string, recipient_rule: string}|null $notificationIntent
     * @param array{task_type: string}|null $taskIntent
     */
    public function __construct(
        public string $key,
        public string $from,
        public string $to,
        public string $operation,
        public string $actionKind,
        public array $permissionKeys,
        public bool $humanRequired,
        public bool $returnEdge,
        public ?int $maxTraversals,
        public ?array $notificationIntent,
        public ?array $taskIntent,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'from' => $this->from,
            'to' => $this->to,
            'operation' => $this->operation,
            'action_kind' => $this->actionKind,
            'permission_keys' => $this->permissionKeys,
            'human_required' => $this->humanRequired,
            'return_edge' => $this->returnEdge,
            'max_traversals' => $this->maxTraversals,
            'notification_intent' => $this->notificationIntent,
            'task_intent' => $this->taskIntent,
        ];
    }
}
