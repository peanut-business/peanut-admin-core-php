<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use think\db\BaseQuery;

/** Applies the authorization AST to a ThinkPHP query without exposing SQL fragments to callers. */
final readonly class ThinkPhpQueryConstraintApplier
{
    public function __construct(private ?TargetSetConstraintApplier $targetSets = null) {}

    public function apply(BaseQuery $query, QueryConstraint $constraint): void
    {
        match (true) {
            $constraint instanceof AlwaysTrue => null,
            $constraint instanceof AlwaysFalse => $query->whereRaw('1 = 0'),
            $constraint instanceof TenantEquals => $query->where($constraint->column->value, $constraint->tenantId),
            $constraint instanceof ColumnEquals => $query->where($constraint->column->value, $constraint->value),
            $constraint instanceof ColumnIn => $query->whereIn($constraint->column->value, $constraint->values),
            $constraint instanceof ColumnNotIn => $query->whereNotIn($constraint->column->value, $constraint->values),
            $constraint instanceof JsonArrayContainsColumn => $this->jsonArrayContains($query, $constraint),
            $constraint instanceof AndConstraint => $this->and($query, $constraint),
            $constraint instanceof OrConstraint => $this->or($query, $constraint),
            $constraint instanceof ExistsByContract => $this->exists($query, $constraint),
            default => throw new DataAuthorizationException(
                'AUTHZ_CONSTRAINT_UNSUPPORTED',
                'The query constraint type is not registered.',
            ),
        };
    }

    private function and(BaseQuery $query, AndConstraint $constraint): void
    {
        $query->where(function (BaseQuery $nested) use ($constraint): void {
            foreach ($constraint->constraints as $child) {
                $this->apply($nested, $child);
            }
        });
    }

    private function or(BaseQuery $query, OrConstraint $constraint): void
    {
        $query->where(function (BaseQuery $nested) use ($constraint): void {
            foreach ($constraint->constraints as $index => $child) {
                $method = $index === 0 ? 'where' : 'whereOr';
                $nested->{$method}(function (BaseQuery $branch) use ($child): void {
                    $this->apply($branch, $child);
                });
            }
        });
    }

    private function exists(BaseQuery $query, ExistsByContract $constraint): void
    {
        if ($constraint->contractKey !== 'data_permission.target-set' || $this->targetSets === null) {
            throw new DataAuthorizationException(
                'AUTHZ_CONSTRAINT_UNSUPPORTED',
                'The EXISTS contract is not registered.',
            );
        }
        $this->targetSets->apply($query, $constraint);
    }

    /** MySQL JSON_TABLE keeps very large caller-authorized target sets to one bound value. */
    private function jsonArrayContains(BaseQuery $query, JsonArrayContainsColumn $constraint): void
    {
        $query->whereRaw(<<<SQL
EXISTS (
    SELECT 1
    FROM JSON_TABLE(
        CAST(? AS JSON),
        '$[*]' COLUMNS (target_id VARCHAR(128) PATH '$')
    ) requested_target
    WHERE requested_target.target_id COLLATE utf8mb4_0900_ai_ci = (
        CAST({$constraint->column->value} AS CHAR CHARACTER SET utf8mb4)
        COLLATE utf8mb4_0900_ai_ci
    )
)
SQL, [json_encode($constraint->values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
    }
}
