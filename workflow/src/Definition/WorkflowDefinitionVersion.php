<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Definition;

use PeanutAdmin\Workflow\Application\WorkflowException;

final readonly class WorkflowDefinitionVersion
{
    /** @param array<string, mixed> $graph */
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $definitionId,
        public int $version,
        public array $graph,
        public string $graphSha256,
        public int $publishedByMemberId,
        public string $publishedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $graph = $row['graph_json'] ?? null;
        if (!is_array($graph) || array_is_list($graph)) {
            throw WorkflowException::internal();
        }

        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['definition_id'],
            (int) $row['version'],
            $graph,
            (string) $row['graph_sha256'],
            (int) $row['published_by_member_id'],
            (string) $row['published_at'],
        );
    }

    public function graph(): WorkflowGraph
    {
        $graph = WorkflowGraph::fromArray($this->graph);
        if (!hash_equals($this->graphSha256, $graph->sha256)) {
            throw WorkflowException::internal();
        }

        return $graph;
    }
}
