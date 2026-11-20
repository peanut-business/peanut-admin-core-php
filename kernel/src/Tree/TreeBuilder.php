<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tree;

final class TreeBuilder
{
    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function build(array $rows, string $id = 'id', string $parent = 'parent_id', string $children = 'children'): array
    {
        $nodes = [];
        foreach ($rows as $row) {
            $key = (string) $row[$id];
            if (isset($nodes[$key])) {
                throw new \InvalidArgumentException('Duplicate tree node.');
            }
            $row[$children] = [];
            $nodes[$key] = $row;
        }
        foreach ($nodes as $key => $node) {
            $seen = [$key => true];
            $cursor = $node[$parent];
            while ($cursor !== null && isset($nodes[(string) $cursor])) {
                $next = (string) $cursor;
                if (isset($seen[$next])) {
                    throw new \InvalidArgumentException('Tree cycle detected.');
                }
                $seen[$next] = true;
                $cursor = $nodes[$next][$parent];
            }
        }
        $roots = [];
        foreach (array_keys($nodes) as $key) {
            $parentKey = $nodes[$key][$parent];
            if ($parentKey !== null && isset($nodes[(string) $parentKey])) {
                $nodes[(string) $parentKey][$children][] =& $nodes[$key];
            } else {
                $roots[] =& $nodes[$key];
            }
        }
        return $roots;
    }

    /** @param list<array<string,mixed>> $rows */
    public function assertCanMove(array $rows, int|string $nodeId, int|string|null $parentId, string $id = 'id', string $parent = 'parent_id'): void
    {
        foreach ($rows as &$row) {
            if ((string) $row[$id] === (string) $nodeId) {
                $row[$parent] = $parentId;
                unset($row);
                $this->build($rows, $id, $parent);
                return;
            }
        }
        throw new \InvalidArgumentException('Tree node not found.');
    }

    /** @param list<array<string,mixed>> $rows */
    public function assertCanDelete(array $rows, int|string $nodeId, string $parent = 'parent_id', string $id = 'id'): void
    {
        $found = false;
        foreach ($rows as $row) {
            if ((string) $row[$id] === (string) $nodeId) {
                $found = true;
            }
        }
        if (!$found) {
            throw new \InvalidArgumentException('Tree node not found.');
        }
        foreach ($rows as $row) {
            if ($row[$parent] !== null && (string) $row[$parent] === (string) $nodeId) {
                throw new \DomainException('Tree node has children.');
            }
        }
    }
}
