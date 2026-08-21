<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Definition;

final readonly class WorkflowNode
{
    /** @param list<array{kind: string, key: string|null}> $assignments */
    public function __construct(
        public string $key,
        public string $type,
        public ?string $completionPolicy,
        public array $assignments,
    ) {}

    /** @return array{key: string, type: string, completion_policy: string|null, assignments: list<array{kind: string, key: string|null}>} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'completion_policy' => $this->completionPolicy,
            'assignments' => $this->assignments,
        ];
    }
}
