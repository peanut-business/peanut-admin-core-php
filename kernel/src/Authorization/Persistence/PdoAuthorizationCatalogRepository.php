<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

use DomainException;
use JsonException;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoRepository;

final class PdoAuthorizationCatalogRepository extends PdoRepository implements AuthorizationCatalogRepository
{
    public function syncPermission(PermissionDefinition $definition): int
    {
        $this->assertOwner('pa_permission', $definition->key, $definition->moduleKey);
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_permission (
    `key`, module_key, type, name, risk_level, status, manifest_version, created_at, updated_at
) VALUES (
    :permission_key, :module_key, :type, :name, :risk_level, 'active', :manifest_version, :created_at, :updated_at
)
ON DUPLICATE KEY UPDATE
    type = VALUES(type), name = VALUES(name), risk_level = VALUES(risk_level),
    status = 'active', manifest_version = VALUES(manifest_version),
    retired_at = NULL, updated_at = VALUES(updated_at)
SQL, [
            'permission_key' => $definition->key,
            'module_key' => $definition->moduleKey,
            'type' => $definition->type,
            'name' => $definition->name,
            'risk_level' => $definition->riskLevel,
            'manifest_version' => $definition->manifestVersion,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->idByKey('pa_permission', $definition->key);
    }

    public function syncProtectedResource(ProtectedResourceDefinition $definition): int
    {
        $this->assertOwner('pa_protected_resource', $definition->key, $definition->moduleKey);
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_protected_resource (
    `key`, module_key, name, ownership, provider_key, status,
    manifest_version, manifest_digest, created_at, updated_at
) VALUES (
    :resource_key, :module_key, :name, :ownership, :provider_key, 'active',
    :manifest_version, :manifest_digest, :created_at, :updated_at
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name), ownership = VALUES(ownership), provider_key = VALUES(provider_key),
    status = 'active', manifest_version = VALUES(manifest_version),
    manifest_digest = VALUES(manifest_digest), retired_at = NULL, updated_at = VALUES(updated_at)
SQL, [
            'resource_key' => $definition->key,
            'module_key' => $definition->moduleKey,
            'name' => $definition->name,
            'ownership' => $definition->ownership,
            'provider_key' => $definition->providerKey,
            'manifest_version' => $definition->manifestVersion,
            'manifest_digest' => $definition->manifestDigest,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->idByKey('pa_protected_resource', $definition->key);
    }

    public function syncTargetType(TargetTypeDefinition $definition): int
    {
        $this->assertOwner('pa_target_type', $definition->key, $definition->moduleKey);
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_target_type (
    `key`, module_key, name, resolver_key, catalog_provider_key, id_format,
    status, manifest_version, manifest_digest, created_at, updated_at
) VALUES (
    :target_key, :module_key, :name, :resolver_key, :catalog_provider_key, :id_format,
    'active', :manifest_version, :manifest_digest, :created_at, :updated_at
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name), resolver_key = VALUES(resolver_key),
    catalog_provider_key = VALUES(catalog_provider_key), id_format = VALUES(id_format),
    status = 'active', manifest_version = VALUES(manifest_version),
    manifest_digest = VALUES(manifest_digest), updated_at = VALUES(updated_at)
SQL, [
            'target_key' => $definition->key,
            'module_key' => $definition->moduleKey,
            'name' => $definition->name,
            'resolver_key' => $definition->resolverKey,
            'catalog_provider_key' => $definition->catalogProviderKey,
            'id_format' => $definition->idFormat,
            'manifest_version' => $definition->manifestVersion,
            'manifest_digest' => $definition->manifestDigest,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->idByKey('pa_target_type', $definition->key);
    }

    public function syncDataCondition(DataConditionDefinition $definition): int
    {
        $this->assertOwner('pa_data_condition_definition', $definition->key, $definition->moduleKey);
        try {
            $configSchema = $definition->configSchema === null
                ? null
                : json_encode($definition->configSchema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new DomainException('Data condition config schema is not valid JSON.');
        }
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_data_condition_definition (
    `key`, module_key, category, target_mode, config_schema_json,
    status, manifest_version, manifest_digest, created_at, updated_at
) VALUES (
    :condition_key, :module_key, :category, :target_mode, :config_schema,
    'active', :manifest_version, :manifest_digest, :created_at, :updated_at
)
ON DUPLICATE KEY UPDATE
    category = VALUES(category), target_mode = VALUES(target_mode),
    config_schema_json = VALUES(config_schema_json), status = 'active',
    manifest_version = VALUES(manifest_version), manifest_digest = VALUES(manifest_digest),
    updated_at = VALUES(updated_at)
SQL, [
            'condition_key' => $definition->key,
            'module_key' => $definition->moduleKey,
            'category' => $definition->category,
            'target_mode' => $definition->targetMode,
            'config_schema' => $configSchema,
            'manifest_version' => $definition->manifestVersion,
            'manifest_digest' => $definition->manifestDigest,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->idByKey('pa_data_condition_definition', $definition->key);
    }

    public function syncResourceOperation(ResourceOperationDefinition $definition): int
    {
        $resourceId = $this->idByKey('pa_protected_resource', $definition->resourceKey);
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_resource_operation (
    protected_resource_id, operation, access_mode, target_cardinality,
    permission_match, audit_level, status, manifest_digest, created_at, updated_at
) VALUES (
    :resource_id, :operation, :access_mode, :target_cardinality,
    :permission_match, :audit_level, 'active', :manifest_digest, :created_at, :updated_at
)
ON DUPLICATE KEY UPDATE
    access_mode = VALUES(access_mode), target_cardinality = VALUES(target_cardinality),
    permission_match = VALUES(permission_match), audit_level = VALUES(audit_level),
    status = 'active', manifest_digest = VALUES(manifest_digest), updated_at = VALUES(updated_at)
SQL, [
            'resource_id' => $resourceId,
            'operation' => $definition->operation,
            'access_mode' => $definition->accessMode,
            'target_cardinality' => $definition->targetCardinality,
            'permission_match' => $definition->permissionMatch,
            'audit_level' => $definition->auditLevel,
            'manifest_digest' => $definition->manifestDigest,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $row = $this->fetchOne(<<<'SQL'
SELECT id FROM pa_resource_operation WHERE protected_resource_id = :resource_id AND operation = :operation
SQL, ['resource_id' => $resourceId, 'operation' => $definition->operation]);

        return $row === null ? throw new DomainException('Resource operation was not synchronized.') : (int) $row['id'];
    }

    public function bindOperationPermission(int $operationId, int $permissionId, int $sortOrder = 0): void
    {
        $this->execute(<<<'SQL'
INSERT INTO pa_resource_operation_permission (resource_operation_id, permission_id, sort_order)
VALUES (:operation_id, :permission_id, :sort_order)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)
SQL, ['operation_id' => $operationId, 'permission_id' => $permissionId, 'sort_order' => $sortOrder]);
    }

    public function bindOperationTargetType(
        int $operationId,
        int $targetTypeId,
        string $targetRole,
        string $inputMode,
        ?int $policySelectionPermissionId,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_resource_operation_target_type (
    resource_operation_id, target_type_id, target_role, input_mode, policy_selection_permission_id
) VALUES (
    :operation_id, :target_type_id, :target_role, :input_mode, :selection_permission_id
)
ON DUPLICATE KEY UPDATE
    input_mode = VALUES(input_mode),
    policy_selection_permission_id = VALUES(policy_selection_permission_id),
    status = 'active'
SQL, [
            'operation_id' => $operationId,
            'target_type_id' => $targetTypeId,
            'target_role' => $targetRole,
            'input_mode' => $inputMode,
            'selection_permission_id' => $policySelectionPermissionId,
        ]);
    }

    public function bindOperationCondition(
        int $operationId,
        int $conditionDefinitionId,
        ?string $selectorResourceKey,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_resource_operation_condition (
    resource_operation_id, condition_definition_id, selector_resource_key, status
) VALUES (
    :operation_id, :condition_id, :selector_resource_key, 'active'
)
ON DUPLICATE KEY UPDATE status = 'active'
SQL, [
            'operation_id' => $operationId,
            'condition_id' => $conditionDefinitionId,
            'selector_resource_key' => $selectorResourceKey,
        ]);
    }

    public function resetOperationRelations(int $operationId): void
    {
        $this->execute(
            'DELETE FROM pa_resource_operation_permission WHERE resource_operation_id = :operation_id',
            ['operation_id' => $operationId],
        );
        $this->execute(
            "UPDATE pa_resource_operation_target_type SET status = 'retired' WHERE resource_operation_id = :operation_id",
            ['operation_id' => $operationId],
        );
        $this->execute(
            "UPDATE pa_resource_operation_condition SET status = 'retired' WHERE resource_operation_id = :operation_id",
            ['operation_id' => $operationId],
        );
    }

    public function permissionId(string $key): int
    {
        return $this->idByKey('pa_permission', $key);
    }

    public function targetTypeId(string $key): int
    {
        return $this->idByKey('pa_target_type', $key);
    }

    public function dataConditionId(string $key): int
    {
        return $this->idByKey('pa_data_condition_definition', $key);
    }

    public function registryRevision(): string
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT manifest_digest FROM pa_protected_resource
UNION ALL SELECT manifest_digest FROM pa_target_type
UNION ALL SELECT manifest_digest FROM pa_resource_operation
ORDER BY manifest_digest
SQL);
        if ($statement === false) {
            throw new DomainException('Could not calculate registry revision.');
        }

        return hash('sha256', implode('|', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function assertOwner(string $table, string $key, string $moduleKey): void
    {
        $row = $this->fetchOne("SELECT module_key FROM `{$table}` WHERE `key` = :catalog_key", [
            'catalog_key' => $key,
        ]);
        if ($row !== null && (string) $row['module_key'] !== $moduleKey) {
            throw new DomainException('Catalog key is already owned by another module.');
        }
    }

    private function idByKey(string $table, string $key): int
    {
        $row = $this->fetchOne("SELECT id FROM `{$table}` WHERE `key` = :catalog_key", [
            'catalog_key' => $key,
        ]);

        return $row === null
            ? throw new DomainException('Catalog entry was not found.')
            : (int) $row['id'];
    }
}
