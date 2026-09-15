<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Definition;

use PeanutAdmin\Workflow\Application\WorkflowException;

final readonly class WorkflowDefinition
{
    /** @param array<string, mixed> $draftGraph */
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $moduleKey,
        public string $workflowKey,
        public string $status,
        public array $draftGraph,
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
        $draftGraph = $row['draft_graph_json'] ?? null;
        if (!is_array($draftGraph) || array_is_list($draftGraph)) {
            throw WorkflowException::internal();
        }

        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['module_key'],
            (string) $row['workflow_key'],
            (string) $row['status'],
            $draftGraph,
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
        $graph = WorkflowGraph::fromArray($this->draftGraph);
        if (!hash_equals($this->draftGraphSha256, $graph->sha256)) {
            throw WorkflowException::internal();
        }

        return $graph;
    }
}
