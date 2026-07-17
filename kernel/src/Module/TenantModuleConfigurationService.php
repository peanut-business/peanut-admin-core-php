<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PDOStatement;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Module\Persistence\PdoModuleRuntimeRepository;
use RuntimeException;
use Throwable;

final readonly class TenantModuleConfigurationService
{
    public function __construct(
        private PDO $pdo,
        private CompiledModuleRegistry $registry,
        private TenantModuleConfigValidator $validator,
    ) {}

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function update(
        int $tenantId,
        string $moduleKey,
        array $config,
        int $expectedRevision,
        int $actorMemberId,
        int $actorAccountId,
        string $requestId,
    ): array {
        $manifest = $this->manifest($moduleKey);
        $this->validator->assertValid($manifest, $config);
        $guard = new ModuleGuard(new PdoModuleRuntimeRepository($this->pdo));
        $guard->assertDeployment($moduleKey);
        $guard->assertTenant($tenantId, $moduleKey, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return $this->transaction(function () use (
            $tenantId,
            $moduleKey,
            $config,
            $expectedRevision,
            $actorMemberId,
            $actorAccountId,
            $requestId,
        ): array {
            $current = $this->row($tenantId, $moduleKey, true);
            if ((int) $current['config_revision'] !== $expectedRevision) {
                throw AdminAccessException::revisionMismatch();
            }
            try {
                $configJson = json_encode((object) $config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new ModuleException('MODULE_CONFIG_INVALID', 'Module configuration is not valid JSON.');
            }
            $now = $this->now();
            $statement = $this->statement(<<<'SQL'
UPDATE pa_tenant_module
SET config_json = :config_json, config_revision = config_revision + 1,
    authorization_revision = authorization_revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND module_key = :module_key
  AND status = 'enabled' AND config_revision = :expected_revision
SQL);
            $statement->execute([
                'config_json' => $configJson,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'module_key' => $moduleKey,
                'expected_revision' => $expectedRevision,
            ]);
            if ($statement->rowCount() !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->statement(<<<'SQL'
UPDATE pa_tenant
SET authorization_revision = authorization_revision + 1, revision = revision + 1, updated_at = :updated_at
WHERE id = :tenant_id
SQL)->execute(['updated_at' => $now, 'tenant_id' => $tenantId]);
            $this->statement(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome, actor_tenant_id, actor_tenant_member_id,
    actor_account_id, actor_type, target_resource_type, target_resource_id,
    target_count, request_id, occurred_at
) VALUES (
    :tenant_id, 'tenant.module.configured', 'core.module.configure', 'success',
    :actor_tenant_id, :actor_member_id, :actor_account_id, 'member',
    'tenant-module', :target_id, 1, :request_id, :occurred_at
)
SQL)->execute([
                'tenant_id' => $tenantId,
                'actor_tenant_id' => $tenantId,
                'actor_member_id' => $actorMemberId,
                'actor_account_id' => $actorAccountId,
                'target_id' => $moduleKey,
                'request_id' => $requestId,
                'occurred_at' => $now,
            ]);

            return $this->normalize($this->row($tenantId, $moduleKey));
        });
    }

    private function manifest(string $moduleKey): ManifestDocument
    {
        foreach ($this->registry->modules as $manifest) {
            if (($manifest->data['key'] ?? null) === $moduleKey) {
                return $manifest;
            }
        }

        throw new ModuleException('MODULE_NOT_INSTALLED', "Unknown module: {$moduleKey}");
    }

    /** @return array<string, mixed> */
    private function row(int $tenantId, string $moduleKey, bool $forUpdate = false): array
    {
        $statement = $this->statement(<<<'SQL'
SELECT module_key, status, source, config_json, config_revision,
       authorization_revision, effective_at, expires_at, enabled_at, disabled_at
FROM pa_tenant_module
WHERE tenant_id = :tenant_id AND module_key = :module_key
SQL . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute(['tenant_id' => $tenantId, 'module_key' => $moduleKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : throw new ModuleException(
            'MODULE_TENANT_DISABLED',
            "Module {$moduleKey} is disabled for tenant.",
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        try {
            $config = json_decode((string) ($row['config_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored module configuration is invalid.', 0, $exception);
        }

        return [
            'module_key' => (string) $row['module_key'],
            'status' => (string) $row['status'],
            'source' => (string) $row['source'],
            'config' => is_array($config) ? $config : [],
            'revision' => (string) $row['config_revision'],
            'authorization_revision' => (string) $row['authorization_revision'],
            'effective_at' => $row['effective_at'],
            'expires_at' => $row['expires_at'],
            'enabled_at' => $row['enabled_at'],
            'disabled_at' => $row['disabled_at'],
        ];
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Could not prepare module configuration statement.');
        }

        return $statement;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
