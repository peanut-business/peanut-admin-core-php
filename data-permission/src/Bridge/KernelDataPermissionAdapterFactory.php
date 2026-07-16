<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Bridge;

use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\DataPermissionAdapter;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;

final class KernelDataPermissionAdapterFactory
{
    private function __construct() {}

    public static function create(DataPermissionEngine $engine): DataPermissionAdapter
    {
        return new DataPermissionAdapter(
            /** @param list<RequestedTargetSet> $targets */
            static fn(
                TenantContext $context,
                string $resourceKey,
                string $operation,
                array $targets,
            ): object => $engine->queryConstraint(
                $context,
                $resourceKey,
                $operation,
                self::targets($targets),
            ),
            /** @param list<RequestedTargetSet> $targets */
            static function (
                TenantContext $context,
                string $resourceKey,
                string $operation,
                array $targets,
            ) use ($engine): void {
                $decision = $engine->decideTargets(
                    $context,
                    $resourceKey,
                    $operation,
                    self::targets($targets),
                );
                if (!$decision->allowed) {
                    throw new DataAuthorizationException($decision->reasonCode, 'The requested targets are denied.');
                }
            },
        );
    }

    /**
     * @param list<RequestedTargetSet> $targets
     */
    private static function targets(array $targets): TypedResourceTargetCollection
    {
        return new TypedResourceTargetCollection(array_map(
            static fn(RequestedTargetSet $target): TypedResourceTargetSet => new TypedResourceTargetSet(
                $target->targetResourceKey,
                $target->targetIds,
                $target->targetRole,
            ),
            $targets,
        ));
    }
}
