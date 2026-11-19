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
        // A top-level grant adds no filter. Keep the ORM's guard against unqualified writes.
        if (!$constraint instanceof AlwaysTrue) {
            $this->appendPredicate($query, $constraint);
        }
    }

    private function appendPredicate(BaseQuery $query, QueryConstraint $constraint): void
    {
        match (true) {
            // ThinkPHP discards empty closures, including the true branch of an OR.
            $constraint instanceof AlwaysTrue => $query->where(true),
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
        $pk = $query->getPk();
        $query->where(function (BaseQuery $nested) use ($constraint, $pk): void {
            $nested->pk($pk);
            foreach ($constraint->constraints as $child) {
                $this->appendPredicate($nested, $child);
            }
        });
    }

    private function or(BaseQuery $query, OrConstraint $constraint): void
    {
        $pk = $query->getPk();
        $query->where(function (BaseQuery $nested) use ($constraint, $pk): void {
            $nested->pk($pk);
            foreach ($constraint->constraints as $index => $child) {
                $method = $index === 0 ? 'where' : 'whereOr';
                $nested->{$method}(function (BaseQuery $branch) use ($child, $pk): void {
                    $branch->pk($pk);
                    $this->appendPredicate($branch, $child);
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
        $allIntegers = $constraint->values !== [];
        foreach ($constraint->values as $value) {
            if (is_int($value)) {
                continue;
            }
            if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
                $allIntegers = false;
                break;
            }
        }
        if ($allIntegers) {
            $intValues = array_map(static fn (int|string $v): int => (int) $v, $constraint->values);
            $query->whereRaw(<<<SQL
{$constraint->column->value} IN (
    SELECT requested_target.target_id
    FROM JSON_TABLE(
        CAST(? AS JSON),
        '$[*]' COLUMNS (target_id BIGINT PATH '$')
    ) requested_target
)
SQL, [json_encode($intValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
            return;
        }
        $query->whereRaw(<<<SQL
{$constraint->column->value} IN (
    SELECT requested_target.target_id
    FROM JSON_TABLE(
        CAST(? AS JSON),
        '$[*]' COLUMNS (target_id VARCHAR(255) PATH '$')
    ) requested_target
)
SQL, [json_encode($constraint->values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
    }
}
