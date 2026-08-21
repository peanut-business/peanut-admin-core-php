<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Definition;

use PeanutAdmin\Workflow\Application\WorkflowException;

final readonly class WorkflowDefinitionVersion
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $definitionId,
        public int $version,
        public string $graphJson,
        public string $graphSha256,
        public int $publishedByMemberId,
        public string $publishedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['definition_id'],
            (int) $row['version'],
            (string) $row['graph_json'],
            (string) $row['graph_sha256'],
            (int) $row['published_by_member_id'],
            (string) $row['published_at'],
        );
    }

    public function graph(): WorkflowGraph
    {
        $graph = WorkflowGraph::fromJson($this->graphJson);
        if (!hash_equals($this->graphSha256, $graph->sha256)) {
            throw WorkflowException::internal();
        }

        return $graph;
    }
}
