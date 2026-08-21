<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Module;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\TenantModuleConfigValidator;
use PeanutAdmin\Kernel\Module\TenantModuleEnableHook;
use PeanutAdmin\Kernel\Module\TenantModuleManager;
use PeanutAdmin\Kernel\Module\TenantModuleMutationRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PHPUnit\Framework\TestCase;

final class TenantModuleManagerTest extends TestCase
{
    public function testEnableRequiresActiveTenantDeploymentDependenciesConfigAndIdempotentHook(): void
    {
        $repository = new InMemoryTenantModuleMutationRepository();
        $hook = new RecordingEnableHook();
        $manager = new TenantModuleManager(
            $this->registry(),
            $repository,
            new class implements TenantModuleConfigValidator {
                public function assertValid(ManifestDocument $manifest, array $config): void
                {
                    if (($config['valid'] ?? false) !== true) {
                        throw new ModuleException('MODULE_CONFIG_INVALID', 'Invalid fixture config.');
                    }
                }
            },
            ['example.work-item' => $hook],
        );
        $now = new DateTimeImmutable('2026-07-16T12:00:00Z');

        $manager->enable(9, 'example.work-item', ['valid' => true], $now);
        $manager->enable(9, 'example.work-item', ['valid' => true], $now);

        self::assertSame(1, $hook->enableCount);
        self::assertSame('enabled', $repository->tenantModule(9, 'example.work-item')?->status);
    }

    public function testDisableIsBlockedByAnEffectiveDependentAndPreservesData(): void
    {
        $repository = new InMemoryTenantModuleMutationRepository();
        $repository->records['example.target'] = new TenantModuleRecord(9, 'example.target', 'enabled', null, null, 1);
        $repository->records['example.work-item'] = new TenantModuleRecord(9, 'example.work-item', 'enabled', null, null, 1);
        $manager = new TenantModuleManager(
            $this->registry(),
            $repository,
            new class implements TenantModuleConfigValidator {
                public function assertValid(ManifestDocument $manifest, array $config): void {}
            },
        );

        try {
            $manager->disable(9, 'example.target', new DateTimeImmutable('2026-07-16T12:00:00Z'));
        } catch (ModuleException $exception) {
            self::assertSame('MODULE_DEPENDENT_ACTIVE', $exception->errorCode);
            self::assertSame('enabled', $repository->records['example.target']->status);

            return;
        }

        self::fail('An active dependent must block disable.');
    }

    private function registry(): CompiledModuleRegistry
    {
        $target = ManifestDocument::fromArray('/tmp/example-target', $this->manifest('example.target'));
        $workItem = ManifestDocument::fromArray('/tmp/example-work-item', $this->manifest('example.work-item', ['example.target']));

        return new CompiledModuleRegistry([$target, $workItem], [], [], [], hash('sha256', $target->digest . '|' . $workItem->digest));
    }

    /**
     * @param list<string> $requires
     * @return array<string, mixed>
     */
    private function manifest(string $key, array $requires = []): array
    {
        return [
            'key' => $key,
            'tenant' => ['requires' => $requires],
        ];
    }
}

final class InMemoryTenantModuleMutationRepository implements TenantModuleMutationRepository
{
    /** @var array<string, TenantModuleRecord> */
    public array $records = [];

    public function tenantIsActive(int $tenantId): bool
    {
        return true;
    }

    public function installation(string $moduleKey): ModuleInstallationRecord
    {
        return new ModuleInstallationRecord($moduleKey, '1.0.0', 'active', 1, 'digest');
    }

    public function tenantModule(int $tenantId, string $moduleKey): ?TenantModuleRecord
    {
        return $this->records[$moduleKey] ?? ($moduleKey === 'example.target'
            ? new TenantModuleRecord($tenantId, $moduleKey, 'enabled', null, null, 1)
            : null);
    }

    public function enabledDependents(int $tenantId, string $moduleKey): array
    {
        return [];
    }

    public function enable(
        int $tenantId,
        string $moduleKey,
        array $config,
        DateTimeImmutable $now,
        string $source = 'manual',
        ?DateTimeImmutable $effectiveAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): TenantModuleRecord {
        return $this->records[$moduleKey] = new TenantModuleRecord(
            $tenantId,
            $moduleKey,
            'enabled',
            $effectiveAt,
            $expiresAt,
            1,
        );
    }

    public function disable(int $tenantId, string $moduleKey, DateTimeImmutable $now): TenantModuleRecord
    {
        return $this->records[$moduleKey] = new TenantModuleRecord($tenantId, $moduleKey, 'disabled', null, null, 2);
    }
}

final class RecordingEnableHook implements TenantModuleEnableHook
{
    public int $enableCount = 0;

    public function enable(int $tenantId, array $config): void
    {
        ++$this->enableCount;
    }

    public function disable(int $tenantId): void {}
}
