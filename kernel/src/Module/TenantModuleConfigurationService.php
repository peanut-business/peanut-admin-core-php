<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use RuntimeException;
use think\facade\Db;

final readonly class TenantModuleConfigurationService
{
    public function __construct(
        private CompiledModuleRegistry $registry,
        private TenantModuleConfigValidator $validator,
        private ModuleRuntimeRepository $modules,
        private AuditService $audit,
    ) {}

    /** @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function update(
        TenantContext $actor,
        string $moduleKey,
        array $config,
        int $expectedRevision,
    ): array {
        $manifest = $this->manifest($moduleKey);
        $this->validator->assertValid($manifest, $config);
        $guard = new ModuleGuard($this->modules);
        $guard->assertDeployment($moduleKey);
        $guard->assertTenant($actor->tenantId, $moduleKey, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return Db::transaction(function () use ($actor, $moduleKey, $config, $expectedRevision): array {
            $current = $this->row($actor->tenantId, $moduleKey, true);
            if ((int) $current['config_revision'] !== $expectedRevision) {
                throw AdminAccessException::revisionMismatch();
            }
            try {
                $configJson = json_encode((object) $config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException) {
                throw new ModuleException('MODULE_CONFIG_INVALID', 'Module configuration is not valid JSON.');
            }
            $now = $this->now();
            if (Db::name('tenant_module')
                ->where('tenant_id', $actor->tenantId)
                ->where('module_key', $moduleKey)
                ->where('status', 'enabled')
                ->where('config_revision', $expectedRevision)
                ->update([
                    'config_json' => $configJson,
                    'config_revision' => Db::raw('config_revision + 1'),
                    'authorization_revision' => Db::raw('authorization_revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            Db::name('tenant')->where('id', $actor->tenantId)->update([
                'authorization_revision' => Db::raw('authorization_revision + 1'),
                'revision' => Db::raw('revision + 1'),
                'updated_at' => $now,
            ]);
            $this->audit->tenantMember(
                context: $actor,
                eventType: 'tenant.module.configured',
                action: 'core.module.configure',
                targetResourceType: 'tenant-module',
                targetResourceId: $moduleKey,
            );

            return $this->normalize($this->row($actor->tenantId, $moduleKey));
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
        $query = Db::name('tenant_module')
            ->where('tenant_id', $tenantId)
            ->where('module_key', $moduleKey);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->field(
            'module_key,status,source,config_json,config_revision,authorization_revision,effective_at,expires_at,enabled_at,disabled_at',
        )->find();

        return $row ?? throw new ModuleException(
            'MODULE_TENANT_DISABLED',
            "Module {$moduleKey} is disabled for tenant.",
        );
    }

    /** @param array<string, mixed> $row
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

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
