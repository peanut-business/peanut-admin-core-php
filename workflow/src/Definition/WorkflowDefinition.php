<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Definition;

use PeanutAdmin\Workflow\Application\WorkflowException;

final readonly class WorkflowDefinition
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $moduleKey,
        public string $workflowKey,
        public string $status,
        public string $draftGraphJson,
        public string $draftGraphSha256,
        public int $latestVersion,
        public int $revision,
        public int $createdByMemberId,
        public int $updatedByMemberId,
        public string $createdAt,
        public string $updatedAt,
        public ?string $retiredAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['module_key'],
            (string) $row['workflow_key'],
            (string) $row['status'],
            (string) $row['draft_graph_json'],
            (string) $row['draft_graph_sha256'],
            (int) $row['latest_version'],
            (int) $row['revision'],
            (int) $row['created_by_member_id'],
            (int) $row['updated_by_member_id'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            $row['retired_at'] === null ? null : (string) $row['retired_at'],
        );
    }

    public function draftGraph(): WorkflowGraph
    {
        $graph = WorkflowGraph::fromJson($this->draftGraphJson);
        if (!hash_equals($this->draftGraphSha256, $graph->sha256)) {
            throw WorkflowException::internal();
        }

        return $graph;
    }
}
