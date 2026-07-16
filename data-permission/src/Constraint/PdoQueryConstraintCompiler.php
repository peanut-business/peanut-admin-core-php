<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;

final class PdoQueryConstraintCompiler implements QueryConstraintCompiler
{
    private int $parameterSequence = 0;

    /** @var array<string, int|string> */
    private array $parameters = [];

    public function compile(QueryConstraint $constraint): CompiledQueryConstraint
    {
        $this->parameterSequence = 0;
        $this->parameters = [];
        $sql = $this->compileNode($constraint);

        return new CompiledQueryConstraint($sql, $this->parameters);
    }

    private function compileNode(QueryConstraint $constraint): string
    {
        return match (true) {
            $constraint instanceof AlwaysTrue => '1 = 1',
            $constraint instanceof AlwaysFalse => '1 = 0',
            $constraint instanceof TenantEquals => $this->equals($constraint->column, $constraint->tenantId),
            $constraint instanceof ColumnEquals => $this->equals($constraint->column, $constraint->value),
            $constraint instanceof ColumnIn => $this->in($constraint),
            $constraint instanceof JsonArrayContainsColumn => $this->jsonArrayContains($constraint),
            $constraint instanceof AndConstraint => $this->combine('AND', $constraint->constraints),
            $constraint instanceof OrConstraint => $this->combine('OR', $constraint->constraints),
            $constraint instanceof ExistsByContract => $this->exists($constraint),
            default => throw new DataAuthorizationException(
                'AUTHZ_CONSTRAINT_UNSUPPORTED',
                'The query constraint type is not registered.',
            ),
        };
    }

    private function equals(ColumnReference $column, int|string $value): string
    {
        $parameter = $this->parameter($value);

        return "{$column->value} = :{$parameter}";
    }

    private function in(ColumnIn $constraint): string
    {
        $parameters = array_map(
            fn(int|string $value): string => ':' . $this->parameter($value),
            $constraint->values,
        );

        return sprintf('%s IN (%s)', $constraint->column->value, implode(', ', $parameters));
    }

    private function jsonArrayContains(JsonArrayContainsColumn $constraint): string
    {
        $parameter = $this->parameter(json_encode(
            $constraint->values,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        return <<<SQL
EXISTS (
    SELECT 1
    FROM JSON_TABLE(
        :{$parameter},
        '$[*]' COLUMNS (target_id VARCHAR(128) PATH '$')
    ) requested_target
    WHERE requested_target.target_id COLLATE utf8mb4_0900_ai_ci = (
        CAST({$constraint->column->value} AS CHAR CHARACTER SET utf8mb4)
        COLLATE utf8mb4_0900_ai_ci
    )
)
SQL;
    }

    /** @param non-empty-list<QueryConstraint> $constraints */
    private function combine(string $operator, array $constraints): string
    {
        return '(' . implode(" {$operator} ", array_map(
            fn(QueryConstraint $constraint): string => $this->compileNode($constraint),
            $constraints,
        )) . ')';
    }

    private function exists(ExistsByContract $constraint): string
    {
        if ($constraint->contractKey !== 'data_permission.target-set') {
            throw new DataAuthorizationException(
                'AUTHZ_CONSTRAINT_UNSUPPORTED',
                'The EXISTS contract is not registered.',
            );
        }
        $tenant = $this->parameter($constraint->tenantId);
        $targetSet = $this->parameter($constraint->targetSetId);

        return <<<SQL
EXISTS (
    SELECT 1
    FROM pa_data_permission_target authz_target
    WHERE authz_target.tenant_id = :{$tenant}
      AND authz_target.target_set_id = :{$targetSet}
      AND authz_target.status = 'active'
      AND authz_target.target_id = (
          CAST({$constraint->outerColumn->value} AS CHAR CHARACTER SET utf8mb4)
          COLLATE utf8mb4_0900_ai_ci
      )
)
SQL;
    }

    private function parameter(int|string $value): string
    {
        $name = 'authz_' . ++$this->parameterSequence;
        $this->parameters[$name] = $value;

        return $name;
    }
}
