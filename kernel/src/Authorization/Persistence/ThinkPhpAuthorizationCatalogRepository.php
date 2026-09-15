<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

use DomainException;
use JsonException;
use PeanutAdmin\Kernel\Authorization\Model\DataConditionDefinitionRecord;
use PeanutAdmin\Kernel\Authorization\Model\ProtectedResourceRecord;
use PeanutAdmin\Kernel\Authorization\Model\ResourceOperationConditionRecord;
use PeanutAdmin\Kernel\Authorization\Model\ResourceOperationPermissionRecord;
use PeanutAdmin\Kernel\Authorization\Model\ResourceOperationRecord;
use PeanutAdmin\Kernel\Authorization\Model\ResourceOperationTargetTypeRecord;
use PeanutAdmin\Kernel\Authorization\Model\TargetTypeRecord;
use PeanutAdmin\Kernel\Persistence\Model\Permission;
use think\Model;

final class ThinkPhpAuthorizationCatalogRepository implements AuthorizationCatalogRepository
{
    public function syncPermission(PermissionDefinition $definition): int
    {
        return $this->syncByKey(Permission::class, $definition->key, $definition->moduleKey, [
            'type' => $definition->type,
            'name' => $definition->name,
            'risk_level' => $definition->riskLevel,
            'status' => 'active',
            'manifest_version' => $definition->manifestVersion,
            'retired_at' => null,
        ]);
    }

    public function syncProtectedResource(ProtectedResourceDefinition $definition): int
    {
        return $this->syncByKey(ProtectedResourceRecord::class, $definition->key, $definition->moduleKey, [
            'name' => $definition->name,
            'ownership' => $definition->ownership,
            'provider_key' => $definition->providerKey,
            'status' => 'active',
            'manifest_version' => $definition->manifestVersion,
            'manifest_digest' => $definition->manifestDigest,
            'retired_at' => null,
        ]);
    }

    public function syncTargetType(TargetTypeDefinition $definition): int
    {
        return $this->syncByKey(TargetTypeRecord::class, $definition->key, $definition->moduleKey, [
            'name' => $definition->name,
            'resolver_key' => $definition->resolverKey,
            'catalog_provider_key' => $definition->catalogProviderKey,
            'id_format' => $definition->idFormat,
            'status' => 'active',
            'manifest_version' => $definition->manifestVersion,
            'manifest_digest' => $definition->manifestDigest,
        ]);
    }

    public function syncDataCondition(DataConditionDefinition $definition): int
    {
        try {
            $configSchema = $definition->configSchema === null
                ? null
                : json_encode($definition->configSchema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new DomainException('Data condition config schema is not valid JSON.');
        }

        return $this->syncByKey(DataConditionDefinitionRecord::class, $definition->key, $definition->moduleKey, [
            'category' => $definition->category,
            'target_mode' => $definition->targetMode,
            'config_schema_json' => $configSchema,
            'status' => 'active',
            'manifest_version' => $definition->manifestVersion,
            'manifest_digest' => $definition->manifestDigest,
        ]);
    }

    public function syncResourceOperation(ResourceOperationDefinition $definition): int
    {
        $resourceId = $this->idByKey(ProtectedResourceRecord::class, $definition->resourceKey);
        $data = [
            'access_mode' => $definition->accessMode,
            'target_cardinality' => $definition->targetCardinality,
            'permission_match' => $definition->permissionMatch,
            'audit_level' => $definition->auditLevel,
            'status' => 'active',
            'manifest_digest' => $definition->manifestDigest,
            'updated_at' => $this->now(),
        ];
        $id = ResourceOperationRecord::where('protected_resource_id', $resourceId)
            ->where('operation', $definition->operation)->value('id');
        if ($id === null) {
            return (int) ResourceOperationRecord::insertGetId([
                'protected_resource_id' => $resourceId,
                'operation' => $definition->operation,
                ...$data,
                'created_at' => $this->now(),
            ]);
        }
        ResourceOperationRecord::where('id', (int) $id)->update($data);

        return (int) $id;
    }

    public function bindOperationPermission(int $operationId, int $permissionId, int $sortOrder = 0): void
    {
        $query = ResourceOperationPermissionRecord::where('resource_operation_id', $operationId)
            ->where('permission_id', $permissionId);
        if ($query->value('resource_operation_id') === null) {
            ResourceOperationPermissionRecord::insert([
                'resource_operation_id' => $operationId,
                'permission_id' => $permissionId,
                'sort_order' => $sortOrder,
            ]);
            return;
        }
        $query->update(['sort_order' => $sortOrder]);
    }

    public function bindOperationTargetType(
        int $operationId,
        int $targetTypeId,
        string $targetRole,
        string $inputMode,
        ?int $policySelectionPermissionId,
    ): void {
        $query = ResourceOperationTargetTypeRecord::where('resource_operation_id', $operationId)
            ->where('target_type_id', $targetTypeId)->where('target_role', $targetRole);
        $data = [
            'input_mode' => $inputMode,
            'policy_selection_permission_id' => $policySelectionPermissionId,
            'status' => 'active',
        ];
        if ($query->value('resource_operation_id') === null) {
            ResourceOperationTargetTypeRecord::insert([
                'resource_operation_id' => $operationId,
                'target_type_id' => $targetTypeId,
                'target_role' => $targetRole,
                ...$data,
            ]);
            return;
        }
        $query->update($data);
    }

    public function bindOperationCondition(
        int $operationId,
        int $conditionDefinitionId,
        ?string $selectorResourceKey,
    ): void {
        $query = ResourceOperationConditionRecord::where('resource_operation_id', $operationId)
            ->where('condition_definition_id', $conditionDefinitionId)
            ->where('selector_resource_key', $selectorResourceKey);
        if ($query->value('resource_operation_id') === null) {
            ResourceOperationConditionRecord::insert([
                'resource_operation_id' => $operationId,
                'condition_definition_id' => $conditionDefinitionId,
                'selector_resource_key' => $selectorResourceKey,
                'status' => 'active',
            ]);
            return;
        }
        $query->update(['status' => 'active']);
    }

    public function resetOperationRelations(int $operationId): void
    {
        ResourceOperationPermissionRecord::where('resource_operation_id', $operationId)->delete();
        ResourceOperationTargetTypeRecord::where('resource_operation_id', $operationId)
            ->update(['status' => 'retired']);
        ResourceOperationConditionRecord::where('resource_operation_id', $operationId)
            ->update(['status' => 'retired']);
    }

    public function permissionId(string $key): int
    {
        return $this->idByKey(Permission::class, $key);
    }

    public function targetTypeId(string $key): int
    {
        return $this->idByKey(TargetTypeRecord::class, $key);
    }

    public function dataConditionId(string $key): int
    {
        return $this->idByKey(DataConditionDefinitionRecord::class, $key);
    }

    public function registryRevision(): string
    {
        $digests = [];
        foreach ([ProtectedResourceRecord::class, TargetTypeRecord::class, ResourceOperationRecord::class] as $model) {
            $digests = [...$digests, ...array_map('strval', $model::column('manifest_digest'))];
        }
        sort($digests, SORT_STRING);

        return hash('sha256', implode('|', $digests));
    }

    /** @param class-string<Model> $model
     * @param array<string, mixed> $data
     */
    private function syncByKey(string $model, string $key, string $moduleKey, array $data): int
    {
        $row = $model::where('key', $key)->field('id,module_key')->find()?->toArray();
        if ($row !== null && (string) $row['module_key'] !== $moduleKey) {
            throw new DomainException('Catalog key is already owned by another module.');
        }
        $now = $this->now();
        if ($row === null) {
            return (int) $model::insertGetId([
                'key' => $key,
                'module_key' => $moduleKey,
                ...$data,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $model::where('id', (int) $row['id'])->update([...$data, 'updated_at' => $now]);

        return (int) $row['id'];
    }

    /** @param class-string<Model> $model */
    private function idByKey(string $model, string $key): int
    {
        $id = $model::where('key', $key)->value('id');

        return $id === null ? throw new DomainException('Catalog entry was not found.') : (int) $id;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.000');
    }
}
