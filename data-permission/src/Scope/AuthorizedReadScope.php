<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Scope;

use PeanutAdmin\DataPermission\Constraint\AndConstraint;
use PeanutAdmin\DataPermission\Constraint\ColumnIn;
use PeanutAdmin\DataPermission\Constraint\ColumnNotIn;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Constraint\OrConstraint;
use PeanutAdmin\DataPermission\Constraint\QueryConstraint;
use PeanutAdmin\DataPermission\Constraint\TenantEquals;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** Server-issued read authority. There is deliberately no request/array deserializer. */
final readonly class AuthorizedReadScope
{
    /** @param non-empty-list<SourceReadScope> $sources */
    public function __construct(
        public TenantContext $actor,
        public string $capability,
        public string $action,
        public array $sources,
        public string $revision,
    ) {
        if (preg_match('/^[a-z][a-z0-9.-]{2,159}$/D', $capability) !== 1
            || preg_match('/^[a-z][a-z0-9.-]{1,63}$/D', $action) !== 1
            || $sources === [] || !array_is_list($sources) || $revision === '') {
            throw new \InvalidArgumentException('READ_SCOPE_INVALID');
        }
        $seen = [];
        foreach ($sources as $source) {
            if (!$source instanceof SourceReadScope || isset($seen[$source->tenantId])) {
                throw new \InvalidArgumentException('READ_SCOPE_INVALID');
            }
            $seen[$source->tenantId] = true;
        }
    }

    /** @return non-empty-list<int> */
    public function sourceTenantIds(): array
    {
        return array_map(static fn(SourceReadScope $source): int => $source->tenantId, $this->sources);
    }

    /** The source module supplies registered columns, never request SQL or table names. */
    public function constraint(ColumnReference $tenantColumn, ColumnReference $objectColumn): QueryConstraint
    {
        $branches = [];
        foreach ($this->sources as $source) {
            $conditions = [new TenantEquals($tenantColumn, $source->tenantId)];
            if ($source->objectIds !== null) {
                $conditions[] = new ColumnIn($objectColumn, $source->objectIds);
            }
            if ($source->deniedObjectIds !== []) {
                $conditions[] = new ColumnNotIn($objectColumn, $source->deniedObjectIds);
            }
            $branches[] = new AndConstraint($conditions);
        }

        return new OrConstraint($branches);
    }

    /** @param non-empty-list<string> $fields */
    public function assertFields(array $fields): void
    {
        if ($fields === [] || !array_is_list($fields)) {
            throw new DataAuthorizationException('AUTHZ_READ_FIELDS_DENIED', 'An explicit output projection is required.');
        }
        foreach ($this->sources as $source) {
            foreach ($fields as $field) {
                if (!is_string($field) || !in_array($field, $source->fields, true)) {
                    throw new DataAuthorizationException(
                        'AUTHZ_READ_FIELDS_DENIED',
                        'The requested output is not granted by every source.',
                    );
                }
            }
        }
    }
}
