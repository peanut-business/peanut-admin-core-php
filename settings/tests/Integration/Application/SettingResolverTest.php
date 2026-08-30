<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Tests\Integration\Application;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Authorization\PermissionRequirement;
use PeanutAdmin\Kernel\Host\AuthorizedExternalOperation;
use PeanutAdmin\Kernel\Host\ExternalOperationDefinition;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use PeanutAdmin\Settings\Application\EffectiveSetting;
use PeanutAdmin\Settings\Application\SettingAdminService;
use PeanutAdmin\Settings\Application\SettingException;
use PeanutAdmin\Settings\Application\SettingResolver;
use PeanutAdmin\Settings\Application\TargetSettingWriter;
use PeanutAdmin\Settings\Cache\ArrayRevisionedSettingCache;
use PeanutAdmin\Settings\Cache\RevisionedSettingCache;
use PeanutAdmin\Settings\Persistence\PdoSettingRepository;
use PeanutAdmin\Settings\Secret\SecretProtector;
use PeanutAdmin\Settings\Secret\SecretStorageContext;
use PeanutAdmin\Settings\Secret\SodiumSecretProtector;
use PeanutAdmin\Settings\Tests\Integration\Schema\SettingsMigrationRunner;
use PeanutAdmin\Settings\Tests\Integration\Support\SettingsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/SettingsDatabaseTestCase.php';

final class SettingResolverTest extends SettingsDatabaseTestCase
{
    public function testInstanceScopePreservesResolutionAndRejectsAnotherLogicalTenant(): void
    {
        $alpha = $this->tenant('alpha');
        $beta = $this->tenant('beta');
        $this->runner->rollbackAll();
        $this->runner = new SettingsMigrationRunner(
            $this->database,
            TenantPersistenceMode::InstanceScoped,
        );
        $this->runner->migrate();
        $registry = $this->registry([$this->definition()], targets: [[
            'module_key' => 'example.module',
            'resource_key' => 'example.project',
            'operation' => 'updateProjectSetting',
            'target_cardinality' => 'one_required',
        ]]);
        $repository = new PdoSettingRepository(
            $this->database,
            TenantPersistenceMode::InstanceScoped,
            $alpha['tenant_id'],
        );
        $repository->synchronize($registry, new DateTimeImmutable(self::NOW . ' UTC'));
        $definition = $registry->require('example.module', 'display-mode');
        $protector = $this->protector();
        $admin = new SettingAdminService($repository, $protector);
        $writer = new TargetSettingWriter($repository, $protector);
        $authorized = $this->authorized($alpha, 'project-1', $this->operation());
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');

        $admin->replaceTenant(
            $definition,
            $alpha['tenant_id'],
            $alpha['member_id'],
            'comfortable',
            $now,
            null,
            null,
            '*',
        );
        $writer->replace($authorized, $definition, 'compact', $now, null, null, '*');
        $resolver = new SettingResolver($repository, $protector, new ArrayRevisionedSettingCache());
        self::assertSame('compact', $resolver->resolveTarget($definition, $authorized, $now)->value);
        self::assertSame(
            'comfortable',
            $writer->unset($authorized, $definition, $now, '"rev-1"')->value,
        );
        $resolver = new SettingResolver($repository, $protector, new ArrayRevisionedSettingCache());
        self::assertSame('comfortable', $resolver->resolveTarget($definition, $authorized, $now)->value);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM pa_setting_tenant_value'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM pa_setting_target_value'));
        $this->expectSettingError(
            'SETTING_NOT_FOUND',
            fn() => $resolver->resolveTenant($definition, $beta['tenant_id'], $now),
        );
    }

    public function testResolvesTargetThenTenantThenDeploymentThenDefaultAtTimeBoundaries(): void
    {
        [$registry, $repository, $protector] = $this->runtime();
        $definition = $registry->require('example.module', 'display-mode');
        $operatorId = $this->operator();
        $tenant = $this->tenant('alpha');
        $admin = new SettingAdminService($repository, $protector);
        $writer = new TargetSettingWriter($repository, $protector);
        $operation = $this->operation();
        $authorized = $this->authorized($tenant, 'project-1', $operation);
        $start = new DateTimeImmutable('2026-07-19T08:00:00Z');
        $expiry = new DateTimeImmutable('2026-07-19T09:00:00Z');

        $admin->replaceDeployment($definition, 'compact', $operatorId, $start, null, null, '*');
        $admin->replaceTenant(
            $definition,
            $tenant['tenant_id'],
            $tenant['member_id'],
            'comfortable',
            $start,
            null,
            null,
            '*',
        );
        $writer->replace(
            $authorized,
            $definition,
            'compact',
            $start,
            $expiry,
            null,
            '*',
        );
        $resolver = new SettingResolver($repository, $protector, new ArrayRevisionedSettingCache());

        self::assertSame('target', $resolver->resolveTarget(
            $definition,
            $authorized,
            $start,
        )->source);
        self::assertSame('tenant', $resolver->resolveTarget(
            $definition,
            $authorized,
            $expiry,
        )->source);
        $expiredTarget = $resolver->resolveTarget($definition, $authorized, $expiry);
        self::assertSame(1, $expiredTarget->revision);
        self::assertSame('"rev-1"', $expiredTarget->etag);

        $admin->unsetTenant(
            $definition,
            $tenant['tenant_id'],
            $tenant['member_id'],
            new DateTimeImmutable('2026-07-19T08:30:00Z'),
            '"rev-1"',
        );
        $tenantResolved = $resolver->resolveTenant($definition, $tenant['tenant_id'], $expiry);
        self::assertSame('deployment', $tenantResolved->source);
        self::assertSame('2026-07-19T08:00:00.000Z', $tenantResolved->effectiveAt);
        self::assertSame(2, $tenantResolved->revision);
        self::assertSame('"rev-2"', $tenantResolved->etag);
        $targetResolved = $resolver->resolveTarget(
            $definition,
            $authorized,
            $expiry,
        );
        self::assertSame('deployment', $targetResolved->source);
        self::assertSame(1, $targetResolved->revision);
        self::assertSame('"rev-1"', $targetResolved->etag);

        $admin->unsetDeployment($definition, $operatorId, $start, '"rev-1"');
        $resolved = $resolver->resolveTarget($definition, $authorized, $expiry);
        self::assertSame('default', $resolved->source);
        self::assertSame('comfortable', $resolved->value);
    }

    public function testMissingManagedScopeUsesDefinitionRevisionAndNullEtag(): void
    {
        [$registry, $repository, $protector] = $this->runtime();
        $definition = $registry->require('example.module', 'display-mode');
        $tenant = $this->tenant('alpha');
        $resolver = new SettingResolver($repository, $protector, new ArrayRevisionedSettingCache());
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');

        $deployment = $resolver->resolveDeployment($definition, $now);
        self::assertSame('default', $deployment->source);
        self::assertSame(1, $deployment->revision);
        self::assertNull($deployment->etag);

        $tenantSetting = $resolver->resolveTenant($definition, $tenant['tenant_id'], $now);
        self::assertSame('default', $tenantSetting->source);
        self::assertSame(1, $tenantSetting->revision);
        self::assertNull($tenantSetting->etag);

        $targetSetting = $resolver->resolveTarget(
            $definition,
            $this->authorized($tenant, 'project-1', $this->operation()),
            $now,
        );
        self::assertSame('default', $targetSetting->source);
        self::assertSame(1, $targetSetting->revision);
        self::assertNull($targetSetting->etag);
    }

    public function testFutureAndExpiredRowsFallThroughButCorruptRowsFailClosed(): void
    {
        [$registry, $repository, $protector] = $this->runtime();
        $definition = $registry->require('example.module', 'display-mode');
        $tenant = $this->tenant('alpha');
        $operatorId = $this->operator();
        $admin = new SettingAdminService($repository, $protector);
        $admin->replaceDeployment(
            $definition,
            'compact',
            $operatorId,
            new DateTimeImmutable('2026-07-20T00:00:00Z'),
            null,
            null,
            '*',
        );
        $resolver = new SettingResolver($repository, $protector, new ArrayRevisionedSettingCache());

        self::assertSame('default', $resolver->resolveTenant(
            $definition,
            $tenant['tenant_id'],
            new DateTimeImmutable('2026-07-19T23:59:59.999Z'),
        )->source);

        $this->database->exec(<<<'SQL'
UPDATE pa_setting_deployment_value
SET value_json = '"corrupt-value"', effective_at = '2026-07-19 00:00:00.000', revision = revision + 1
SQL);
        $this->expectSettingError('SETTING_STORED_VALUE_INVALID', fn() => $resolver->resolveTenant(
            $definition,
            $tenant['tenant_id'],
            new DateTimeImmutable('2026-07-19T23:59:59.999Z'),
        ));
    }

    public function testRequiredDefinitionFailsClosedWhenNoEffectiveValueExists(): void
    {
        $definitionData = [
            'key' => 'required-region',
            'name' => 'Required region',
            'description' => 'Required generic region.',
            'schema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'string',
                'minLength' => 2,
            ],
            'required' => true,
            'secret' => false,
            'allowed_scopes' => ['tenant'],
            'target_resource_key' => null,
            'target_operation' => null,
        ];
        $registry = $this->registry([$definitionData]);
        $repository = $this->synchronize($registry);
        $tenant = $this->tenant('alpha');
        $resolver = new SettingResolver($repository, $this->protector(), new ArrayRevisionedSettingCache());

        $this->expectSettingError('SETTING_REQUIRED_VALUE_MISSING', fn() => $resolver->resolveTenant(
            $registry->require('example.module', 'required-region'),
            $tenant['tenant_id'],
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
        ));
    }

    public function testRevisionAddressedCacheCannotReturnStaleValueAfterCommit(): void
    {
        [$registry, $repository, $protector] = $this->runtime();
        $definition = $registry->require('example.module', 'display-mode');
        $tenant = $this->tenant('alpha');
        $admin = new SettingAdminService($repository, $protector);
        $cache = new ArrayRevisionedSettingCache();
        $resolver = new SettingResolver($repository, $protector, $cache);
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');

        $admin->replaceTenant(
            $definition,
            $tenant['tenant_id'],
            $tenant['member_id'],
            'compact',
            $now,
            null,
            null,
            '*',
        );
        self::assertSame('compact', $resolver->resolveTenant($definition, $tenant['tenant_id'], $now)->value);
        self::assertSame('compact', $resolver->resolveTenant($definition, $tenant['tenant_id'], $now)->value);
        self::assertSame(1, $cache->hits());

        $admin->replaceTenant(
            $definition,
            $tenant['tenant_id'],
            $tenant['member_id'],
            'comfortable',
            $now,
            null,
            '"rev-1"',
            null,
        );
        self::assertSame('comfortable', $resolver->resolveTenant($definition, $tenant['tenant_id'], $now)->value);
        self::assertSame(1, $cache->hits());
    }

    public function testCacheKeyReusesStableEffectivePhasesAcrossAsOfMicroseconds(): void
    {
        [$registry, $repository, $protector] = $this->runtime();
        $definition = $registry->require('example.module', 'display-mode');
        $tenant = $this->tenant('alpha');
        (new SettingAdminService($repository, $protector))->replaceTenant(
            $definition,
            $tenant['tenant_id'],
            $tenant['member_id'],
            'compact',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            new DateTimeImmutable('2026-07-19T09:00:00Z'),
            null,
            '*',
        );
        $cache = new ArrayRevisionedSettingCache();
        $resolver = new SettingResolver($repository, $protector, $cache);

        self::assertSame('compact', $resolver->resolveTenant(
            $definition,
            $tenant['tenant_id'],
            new DateTimeImmutable('2026-07-19T08:00:00.000001Z'),
        )->value);
        self::assertSame('compact', $resolver->resolveTenant(
            $definition,
            $tenant['tenant_id'],
            new DateTimeImmutable('2026-07-19T08:59:59.999999Z'),
        )->value);
        self::assertSame(1, $cache->hits());

        self::assertSame('default', $resolver->resolveTenant(
            $definition,
            $tenant['tenant_id'],
            new DateTimeImmutable('2026-07-19T09:00:00Z'),
        )->source);
        self::assertSame('default', $resolver->resolveTenant(
            $definition,
            $tenant['tenant_id'],
            new DateTimeImmutable('2026-07-19T09:00:00.000001Z'),
        )->source);
        self::assertSame(2, $cache->hits());
    }

    public function testCacheHitValidatesSameRevisionStoredJsonBeforeReturning(): void
    {
        [$registry, $repository, $protector] = $this->runtime();
        $definition = $registry->require('example.module', 'display-mode');
        $tenant = $this->tenant('alpha');
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');
        (new SettingAdminService($repository, $protector))->replaceTenant(
            $definition,
            $tenant['tenant_id'],
            $tenant['member_id'],
            'compact',
            $now,
            null,
            null,
            '*',
        );
        $cache = new ArrayRevisionedSettingCache();
        $resolver = new SettingResolver($repository, $protector, $cache);
        self::assertSame('compact', $resolver->resolveTenant($definition, $tenant['tenant_id'], $now)->value);

        $this->database->exec(<<<'SQL'
UPDATE pa_setting_tenant_value SET value_json = '"corrupt-value"'
SQL);

        $this->expectSettingError('SETTING_STORED_VALUE_INVALID', fn() => $resolver->resolveTenant(
            $definition,
            $tenant['tenant_id'],
            $now,
        ));
    }

    public function testCacheHitFailsClosedWhenSecretKeyOrCiphertextChangesAtSameRevision(): void
    {
        $registry = $this->registry([$this->secretDefinition()]);
        $repository = $this->synchronize($registry);
        $definition = $registry->require('example.module', 'runtime-secret');
        $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $protector = new SodiumSecretProtector(['runtime' => $key], 'runtime');
        $operatorId = $this->operator();
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');
        (new SettingAdminService($repository, $protector))->replaceDeployment(
            $definition,
            'runtime-value',
            $operatorId,
            $now,
            null,
            null,
            '*',
        );
        $cache = new ArrayRevisionedSettingCache();
        $resolver = new SettingResolver($repository, $protector, $cache);
        self::assertSame('runtime-value', $resolver->resolveDeployment($definition, $now)->value);

        $missingKeyResolver = new SettingResolver(
            $repository,
            new SodiumSecretProtector([
                'replacement' => random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
            ], 'replacement'),
            $cache,
        );
        $this->expectSettingError(
            'SETTING_SECRET_UNAVAILABLE',
            fn() => $missingKeyResolver->resolveDeployment($definition, $now),
        );

        $statement = $this->database->prepare(
            'UPDATE pa_setting_deployment_value SET ciphertext = :ciphertext',
        );
        $statement->execute(['ciphertext' => random_bytes(48)]);
        $this->expectSettingError(
            'SETTING_SECRET_UNAVAILABLE',
            fn() => $resolver->resolveDeployment($definition, $now),
        );
    }

    public function testResolvesDeploymentValueAndDefaultWithoutTenantContext(): void
    {
        $registry = $this->registry([$this->definition([
            'allowed_scopes' => ['deployment'],
            'target_resource_key' => null,
            'target_operation' => null,
        ])]);
        $repository = $this->synchronize($registry);
        $definition = $registry->require('example.module', 'display-mode');
        $resolver = new SettingResolver($repository, $this->protector(), new ArrayRevisionedSettingCache());
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');

        self::assertSame('comfortable', $resolver->resolveDeployment($definition, $now)->value);
        self::assertSame('default', $resolver->resolveDeployment($definition, $now)->source);

        (new SettingAdminService($repository, $this->protector()))->replaceDeployment(
            $definition,
            'compact',
            $this->operator(),
            $now,
            null,
            null,
            '*',
        );
        self::assertSame('compact', $resolver->resolveDeployment($definition, $now)->value);
        self::assertSame('deployment', $resolver->resolveDeployment($definition, $now)->source);
    }

    public function testSecretResolutionAuthenticatesAndReturnsPlaintextOnlyToRuntimeCaller(): void
    {
        $secret = $this->secretDefinition(['allowed_scopes' => ['tenant']]);
        $registry = $this->registry([$secret]);
        $repository = $this->synchronize($registry);
        $protector = $this->protector();
        $tenant = $this->tenant('alpha');
        $definition = $registry->require('example.module', 'runtime-secret');
        (new SettingAdminService($repository, $protector))->replaceTenant(
            $definition,
            $tenant['tenant_id'],
            $tenant['member_id'],
            'runtime-value',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            null,
            null,
            '*',
        );

        $resolved = (new SettingResolver(
            $repository,
            $protector,
            new ArrayRevisionedSettingCache(),
        ))->resolveTenant($definition, $tenant['tenant_id'], new DateTimeImmutable('2026-07-19T08:00:00Z'));
        self::assertSame('runtime-value', $resolved->value);
        self::assertTrue($resolved->secret);
        self::assertArrayNotHasKey('value', $resolved->toAdminArray());
    }

    public function testSecretResolutionBypassesCacheReadsAndWrites(): void
    {
        $registry = $this->registry([$this->secretDefinition(['allowed_scopes' => ['tenant']])]);
        $repository = $this->synchronize($registry);
        $protector = $this->protector();
        $tenant = $this->tenant('alpha');
        $definition = $registry->require('example.module', 'runtime-secret');
        (new SettingAdminService($repository, $protector))->replaceTenant(
            $definition,
            $tenant['tenant_id'],
            $tenant['member_id'],
            'runtime-value',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            null,
            null,
            '*',
        );
        $cache = new class implements RevisionedSettingCache {
            public int $reads = 0;
            public int $writes = 0;

            public function get(string $key): ?EffectiveSetting
            {
                ++$this->reads;

                return null;
            }

            public function put(string $key, EffectiveSetting $setting): void
            {
                ++$this->writes;
            }
        };
        $resolver = new SettingResolver($repository, $protector, $cache);

        self::assertSame('runtime-value', $resolver->resolveTenant(
            $definition,
            $tenant['tenant_id'],
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
        )->value);
        self::assertSame('runtime-value', $resolver->resolveTenant(
            $definition,
            $tenant['tenant_id'],
            new DateTimeImmutable('2026-07-19T08:30:00Z'),
        )->value);
        self::assertSame(0, $cache->reads);
        self::assertSame(0, $cache->writes);
    }

    public function testSecretCandidatesAreDecryptedOnceAndInactiveCorruptionFailsClosed(): void
    {
        $registry = $this->registry([$this->secretDefinition([
            'allowed_scopes' => ['deployment', 'tenant'],
        ])]);
        $repository = $this->synchronize($registry);
        $definition = $registry->require('example.module', 'runtime-secret');
        $tenant = $this->tenant('alpha');
        $delegate = $this->protector();
        $protector = new class ($delegate) implements SecretProtector {
            public int $revealCalls = 0;

            public function __construct(private SecretProtector $delegate) {}

            public function protect(string $plaintext, SecretStorageContext $context): array
            {
                return $this->delegate->protect($plaintext, $context);
            }

            public function reveal(
                string $ciphertext,
                string $nonce,
                string $keyId,
                SecretStorageContext $context,
            ): string {
                ++$this->revealCalls;

                return $this->delegate->reveal($ciphertext, $nonce, $keyId, $context);
            }
        };
        $admin = new SettingAdminService($repository, $protector);
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');
        $admin->replaceDeployment(
            $definition,
            'deployment-secret',
            $this->operator(),
            $now,
            null,
            null,
            '*',
        );
        $admin->replaceTenant(
            $definition,
            $tenant['tenant_id'],
            $tenant['member_id'],
            'tenant-secret',
            $now,
            null,
            null,
            '*',
        );
        $protector->revealCalls = 0;
        $resolver = new SettingResolver($repository, $protector, new ArrayRevisionedSettingCache());

        self::assertSame('tenant-secret', $resolver->resolveTenant(
            $definition,
            $tenant['tenant_id'],
            $now,
        )->value);
        self::assertSame(2, $protector->revealCalls);

        $this->database->exec(<<<'SQL'
UPDATE pa_setting_deployment_value
SET ciphertext = RANDOM_BYTES(48), effective_at = '2026-07-20 00:00:00.000'
SQL);
        $protector->revealCalls = 0;
        $this->expectSettingError('SETTING_SECRET_UNAVAILABLE', fn() => $resolver->resolveTenant(
            $definition,
            $tenant['tenant_id'],
            $now,
        ));
        self::assertSame(2, $protector->revealCalls);
    }

    /** @return array{\PeanutAdmin\Settings\Definition\SettingDefinitionRegistry, \PeanutAdmin\Settings\Persistence\PdoSettingRepository, SodiumSecretProtector} */
    private function runtime(): array
    {
        $targets = [[
            'module_key' => 'example.module',
            'resource_key' => 'example.project',
            'operation' => 'updateProjectSetting',
            'target_cardinality' => 'one_required',
        ]];
        $registry = $this->registry([$this->definition()], targets: $targets);

        return [$registry, $this->synchronize($registry), $this->protector()];
    }

    /** @param array{tenant_id: int, member_id: int} $tenant */
    private function authorized(
        array $tenant,
        string $targetId,
        ExternalOperationDefinition $operation,
    ): AuthorizedExternalOperation {
        return $this->authorizeTarget($tenant, 'example.project', [$targetId], $operation);
    }

    private function operation(): ExternalOperationDefinition
    {
        return new ExternalOperationDefinition(
            'updateProjectSetting',
            'PUT',
            '/api/example/v1/projects/{project_id}/setting',
            'tenant',
            'example.module',
            new PermissionRequirement('tenant', ['example.module.manage']),
            'example.project',
            'targets',
            'one_required',
            true,
            true,
        );
    }

    private function protector(): SodiumSecretProtector
    {
        return new SodiumSecretProtector([
            'runtime' => random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        ], 'runtime');
    }

    /** @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function secretDefinition(array $override = []): array
    {
        return array_merge([
            'key' => 'runtime-secret',
            'name' => 'Runtime secret',
            'description' => 'Required by a generic integration.',
            'schema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 64,
            ],
            'required' => true,
            'secret' => true,
            'allowed_scopes' => ['deployment'],
            'target_resource_key' => null,
            'target_operation' => null,
        ], $override);
    }

    private function expectSettingError(string $code, callable $operation): void
    {
        try {
            $operation();
        } catch (SettingException $exception) {
            self::assertSame($code, $exception->errorCode);

            return;
        }
        self::fail("Expected settings error {$code}.");
    }
}
