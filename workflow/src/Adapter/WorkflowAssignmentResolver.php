<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface WorkflowAssignmentResolver
{
    /**
     * @param non-empty-list<array{kind: string, key: string|null}> $rules
     * @return list<array{source_kind: string, source_key: string, member_id: int}>
     */
    public function resolve(
        AuthorizedOperationContext $context,
        array $rules,
        int $initiatorMemberId,
        ?int $previousActorMemberId,
    ): array;
}
