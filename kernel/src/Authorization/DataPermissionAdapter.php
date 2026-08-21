<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use Closure;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;

final readonly class DataPermissionAdapter
{
    /** @var Closure(TenantContext, string, string, list<RequestedTargetSet>): object */
    private Closure $queryConstraint;

    /** @var Closure(TenantContext, string, string, list<RequestedTargetSet>): void */
    private Closure $targetAssertion;

    /**
     * @param callable(TenantContext, string, string, list<RequestedTargetSet>): object $queryConstraint
     * @param callable(TenantContext, string, string, list<RequestedTargetSet>): void $targetAssertion
     */
    public function __construct(callable $queryConstraint, callable $targetAssertion)
    {
        $this->queryConstraint = Closure::fromCallable($queryConstraint);
        $this->targetAssertion = Closure::fromCallable($targetAssertion);
    }

    /** @param list<RequestedTargetSet> $requestedTargets */
    public function queryConstraint(
        TenantContext $context,
        string $resourceKey,
        string $operation,
        array $requestedTargets = [],
    ): object {
        return ($this->queryConstraint)($context, $resourceKey, $operation, $requestedTargets);
    }

    /** @param list<RequestedTargetSet> $requestedTargets */
    public function assertTargetsAllowed(
        TenantContext $context,
        string $resourceKey,
        string $operation,
        array $requestedTargets,
    ): void {
        ($this->targetAssertion)($context, $resourceKey, $operation, $requestedTargets);
    }
}
