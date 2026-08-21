<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

interface AuthorizationCatalogRepository
{
    public function syncPermission(PermissionDefinition $definition): int;

    public function syncProtectedResource(ProtectedResourceDefinition $definition): int;

    public function syncTargetType(TargetTypeDefinition $definition): int;

    public function syncDataCondition(DataConditionDefinition $definition): int;

    public function syncResourceOperation(ResourceOperationDefinition $definition): int;

    public function bindOperationPermission(int $operationId, int $permissionId, int $sortOrder = 0): void;

    public function bindOperationTargetType(
        int $operationId,
        int $targetTypeId,
        string $targetRole,
        string $inputMode,
        ?int $policySelectionPermissionId,
    ): void;

    public function bindOperationCondition(
        int $operationId,
        int $conditionDefinitionId,
        ?string $selectorResourceKey,
    ): void;

    public function resetOperationRelations(int $operationId): void;

    public function permissionId(string $key): int;

    public function targetTypeId(string $key): int;

    public function dataConditionId(string $key): int;

    public function registryRevision(): string;
}
