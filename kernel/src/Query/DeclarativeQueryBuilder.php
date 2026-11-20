<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Query;

use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use think\db\BaseQuery;

/** Adds filters to the caller's existing scoped query; it never creates a query or clears scopes. */
final readonly class DeclarativeQueryBuilder
{
    /**
     * @param array<string,string> $fields API field to trusted database column
     * @param array<string,callable(BaseQuery,mixed):void> $relations Trusted relation predicates
     */
    public function __construct(private array $fields, private array $relations = []) {}

    /**
     * @param list<array{field:string,operator:string,value?:mixed}> $filters
     * @param array<string,string> $sort
     */
    public function apply(BaseQuery $query, array $filters, ?PageRequest $page = null, array $sort = []): BaseQuery
    {
        foreach ($filters as $filter) {
            if ($filter['operator'] === 'hasWhere') {
                $relation = $this->relations[$filter['field']] ?? throw new \InvalidArgumentException('Relation is not allowed.');
                $relation($query, $filter['value'] ?? null);
                continue;
            }
            $column = $this->fields[$filter['field']] ?? throw new \InvalidArgumentException('Filter field is not allowed.');
            $value = $filter['value'] ?? null;
            switch ($filter['operator']) {
                case '=':
                    if (!is_scalar($value)) {
                        throw new \InvalidArgumentException('Equality requires a scalar value.');
                    }
                    $query->where($column, '=', $value);
                    break;
                case 'like':
                    if (!is_string($value)) {
                        throw new \InvalidArgumentException('LIKE requires a string.');
                    }
                    $query->where($column, $filter['operator'], $value);
                    break;
                case 'in':
                    if (!is_array($value) || !array_is_list($value) || $value === [] || count(array_filter($value, 'is_scalar')) !== count($value)) {
                        throw new \InvalidArgumentException('IN requires values.');
                    }
                    $query->whereIn($column, $value);
                    break;
                case 'between': case 'date_range':
                    if (!is_array($value) || !array_is_list($value) || count($value) !== 2 || count(array_filter($value, 'is_scalar')) !== 2) {
                        throw new \InvalidArgumentException('Range requires two values.');
                    }
                    $query->whereBetween($column, $value);
                    break;
                case 'null':
                    if (!is_bool($value)) {
                        throw new \InvalidArgumentException('NULL requires a boolean.');
                    }
                    $value ? $query->whereNull($column) : $query->whereNotNull($column);
                    break;
                default:
                    throw new \InvalidArgumentException('Filter operator is not allowed.');
            }
        }
        foreach ($sort as $field => $direction) {
            $column = $this->fields[$field] ?? throw new \InvalidArgumentException('Sort field is not allowed.');
            if (!in_array($direction, ['asc', 'desc'], true)) {
                throw new \InvalidArgumentException('Sort direction is not allowed.');
            }
            $query->order($column, $direction);
        }
        if ($page !== null) {
            $query->limit($page->offset(), $page->pageSize);
        }
        return $query;
    }

    /** @param list<array{field:string,operator:string,value?:mixed}> $filters */
    public function hasWhere(array $filters, string $field): bool
    {
        foreach ($filters as $filter) {
            if ($filter['field'] === $field && $filter['operator'] === 'hasWhere') {
                return true;
            }
        }
        return false;
    }
}
