<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Tests\Security;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Authorization\PermissionRequirement;
use PeanutAdmin\Kernel\Host\AuthorizedExternalOperation;
use PeanutAdmin\Kernel\Host\ExternalOperationDefinition;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use PeanutAdmin\Settings\Application\SettingAdminService;
use PeanutAdmin\Settings\Application\SettingException;
use PeanutAdmin\Settings\Application\SettingResolver;
use PeanutAdmin\Settings\Application\TargetSettingWriter;
use PeanutAdmin\Settings\Cache\ArrayRevisionedSettingCache;
use PeanutAdmin\Settings\Persistence\PdoSettingRepository;
use PeanutAdmin\Settings\Secret\SodiumSecretProtector;
use PeanutAdmin\Settings\Tests\Integration\Support\SettingsDatabaseTestCase;

require_once dirname(__DIR__) . '/Integration/Support/SettingsDatabaseTestCase.php';

final class SettingsIsolationTest extends SettingsDatabaseTestCase
{
    public function testExplicitInstanceScopeRejectsTenantScopedStorageBeforeMutation(): void
    {
        $tenant = $this->tenant('alpha');
        $registry = $this->registry([$this->definition([
            'allowed_scopes' => ['tenant'],
            'target_resource_key' => null,
            'target_operation' => null,
        ])]);
        $repository = new PdoSettingRepository(
            $this->database,
            TenantPersistenceMode::InstanceScoped,
            $tenant['tenant_id'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TENANT_PERSISTENCE_SCHEMA_MODE_MISMATCH');
        $repository->synchronize($registry, new DateTimeImmutable(self::NOW . ' UTC'));
    }

    public function testTenantReadsAndWritesCannotCrossTenantBoundary(): void
    {
        [$definition, $repository, $protector] = $this->runtime();
        $alpha = $this->tenant('alpha');
        $beta = $this->tenant('beta');
        $admin = new SettingAdminService($repository, $protector);
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');
        $admin->replaceTenant(
            $definition,
            $alpha['tenant_id'],
            $alpha['member_id'],
            'compact',
            $now,
            null,
            null,
            '*',
        );
        $admin->replaceTenant(
            $definition,
            $beta['tenant_id'],
            $beta['member_id'],
            'comfortable',
            $now,
            null,
            null,
            '*',
        );
        $resolver = new SettingResolver($repository, $protector, new ArrayRevisionedSettingCache());

        self::assertSame('compact', $resolver->resolveTenant($definition, $alpha['tenant_id'], $now)->value);
        self::assertSame('comfortable', $resolver->resolveTenant($definition, $beta['tenant_id'], $now)->value);
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM pa_setting_tenant_value WHERE tenant_id = ' . $alpha['tenant_id'],
        ));
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM pa_setting_tenant_value WHERE tenant_id = ' . $beta['tenant_id'],
        ));
    }

    public function testTenantMutationRejectsActorFromAnotherTenantBeforeWriting(): void
    {
        [$definition, $repository, $protector] = $this->runtime();
        $alpha = $this->tenant('alpha');
        $beta = $this->tenant('beta');

        $this->expectDenied(fn() => (new SettingAdminService($repository, $protector))->replaceTenant(
            $definition,
            $alpha['tenant_id'],
            $beta['member_id'],
            'compact',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            null,
            null,
            '*',
        ));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pa_setting_tenant_value'));
    }

    public function testTargetWriterRequiresExactAuthorizedModuleOperationResourceAndOneTarget(): void
    {
        [$definition, $repository, $protector] = $this->runtime();
        $tenant = $this->tenant('alpha');
        $writer = new TargetSettingWriter($repository, $protector);
        $validOperation = $this->operation('example.module', 'example.project', 'updateProjectSetting');
        $validAuthorization = $this->authorization(
            $tenant,
            'example.project',
            ['project-1'],
            $validOperation,
        );

        $result = $writer->replace(
            $validAuthorization,
            $definition,
            'compact',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            null,
            null,
            '*',
        );
        self::assertSame('target', $result->source);

        foreach ([
            $this->authorization(
                $tenant,
                'example.project',
                ['project-1'],
                $this->operation('other.module', 'example.project', 'updateProjectSetting'),
            ),
            $this->authorization(
                $tenant,
                'example.project',
                ['project-1'],
                $this->operation('example.module', 'example.other', 'updateProjectSetting'),
            ),
            $this->authorization(
                $tenant,
                'example.project',
                ['project-1'],
                $this->operation('example.module', 'example.project', 'otherOperation'),
            ),
            $this->authorization($tenant, 'example.other', ['project-1'], $validOperation),
        ] as $authorization) {
            $this->expectDenied(fn() => $writer->replace(
                $authorization,
                $definition,
                'comfortable',
                new DateTimeImmutable('2026-07-19T08:00:00Z'),
                null,
                null,
                '*',
            ));
        }
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM pa_setting_target_value'));
    }

    public function testTargetWriterRejectsHostIssuedGrantForAnotherOperationOwner(): void
    {
        [$definition, $repository, $protector] = $this->runtime();
        $tenant = $this->tenant('alpha');
        $mismatched = $this->authorization(
            $tenant,
            'example.project',
            ['project-1'],
            $this->operation('other.module', 'example.project', 'updateProjectSetting'),
        );

        $this->expectDenied(fn() => (new TargetSettingWriter($repository, $protector))->replace(
            $mismatched,
            $definition,
            'compact',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            null,
            null,
            '*',
        ));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pa_setting_target_value'));
    }

    public function testTargetResolverAppliesTheSameAuthorizationBoundaryBeforeReadingExistence(): void
    {
        [$definition, $repository, $protector] = $this->runtime();
        $tenant = $this->tenant('alpha');
        $validOperation = $this->operation('example.module', 'example.project', 'updateProjectSetting');
        $authorization = $this->authorization(
            $tenant,
            'example.project',
            ['hidden-target'],
            $validOperation,
        );
        (new TargetSettingWriter($repository, $protector))->replace(
            $authorization,
            $definition,
            'compact',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            null,
            null,
            '*',
        );
        $resolver = new SettingResolver($repository, $protector, new ArrayRevisionedSettingCache());

        foreach ([
            $this->authorization($tenant, 'example.other', ['hidden-target'], $validOperation),
        ] as $invalid) {
            $this->expectDenied(fn() => $resolver->resolveTarget(
                $definition,
                $invalid,
                new DateTimeImmutable('2026-07-19T08:00:00Z'),
            ));
        }
    }

    public function testTargetWriterRejectsSubMillisecondTimestampsForReplaceAndUnset(): void
    {
        [$definition, $repository, $protector] = $this->runtime();
        $tenant = $this->tenant('alpha');
        $authorized = $this->authorization(
            $tenant,
            'example.project',
            ['project-1'],
            $this->operation('example.module', 'example.project', 'updateProjectSetting'),
        );
        $writer = new TargetSettingWriter($repository, $protector);
        $exact = new DateTimeImmutable('2026-07-19T08:00:00.123000Z');
        $subMillisecond = new DateTimeImmutable('2026-07-19T08:00:00.123456Z');

        $this->expectIntervalError(fn() => $writer->replace(
            $authorized,
            $definition,
            'compact',
            $subMillisecond,
            null,
            null,
            '*',
        ));
        $this->expectIntervalError(fn() => $writer->replace(
            $authorized,
            $definition,
            'compact',
            $exact,
            $subMillisecond->modify('+1 second'),
            null,
            '*',
        ));
        $this->expectIntervalError(fn() => $writer->unset(
            $authorized,
            $definition,
            $subMillisecond,
            null,
        ));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pa_setting_target_value'));
    }

    public function testTargetWritesReturnTenantFallbackWithTargetPreconditions(): void
    {
        [$definition, $repository, $protector] = $this->runtime();
        $tenant = $this->tenant('alpha');
        $authorized = $this->authorization(
            $tenant,
            'example.project',
            ['project-1'],
            $this->operation('example.module', 'example.project', 'updateProjectSetting'),
        );
        $asOf = new DateTimeImmutable('2026-07-19T08:00:00Z');
        $activeAt = new DateTimeImmutable('2026-07-19T07:00:00Z');
        $futureAt = new DateTimeImmutable('2026-07-19T09:00:00Z');
        $admin = new SettingAdminService($repository, $protector);
        $admin->replaceDeployment(
            $definition,
            'compact',
            $this->operator(),
            $activeAt,
            null,
            null,
            '*',
            $asOf,
        );
        $admin->replaceTenant(
            $definition,
            $tenant['tenant_id'],
            $tenant['member_id'],
            'comfortable',
            $activeAt,
            null,
            null,
            '*',
            $asOf,
        );
        $writer = new TargetSettingWriter($repository, $protector);

        $future = $writer->replace(
            $authorized,
            $definition,
            'compact',
            $futureAt,
            null,
            null,
            '*',
            $asOf,
        );
        self::assertSame('comfortable', $future->value);
        self::assertSame('tenant', $future->source);
        self::assertTrue($future->configured);
        self::assertSame('2026-07-19T07:00:00.000Z', $future->effectiveAt);
        self::assertSame(1, $future->revision);
        self::assertSame('"rev-1"', $future->etag);

        $unset = $writer->unset(
            $authorized,
            $definition,
            $asOf,
            '"rev-1"',
            $asOf,
        );
        self::assertSame('comfortable', $unset->value);
        self::assertSame('tenant', $unset->source);
        self::assertTrue($unset->configured);
        self::assertSame('2026-07-19T07:00:00.000Z', $unset->effectiveAt);
        self::assertSame(2, $unset->revision);
        self::assertSame('"rev-2"', $unset->etag);
    }

    public function testSecretCiphertextCannotCrossTenantIdentity(): void
    {
        $registry = $this->registry([$this->secretDefinition(['allowed_scopes' => ['tenant']])]);
        $repository = $this->synchronize($registry);
        $definition = $registry->require('example.module', 'runtime-secret');
        $protector = $this->secretProtector();
        $alpha = $this->tenant('alpha');
        $beta = $this->tenant('beta');
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');
        $admin = new SettingAdminService($repository, $protector);
        $admin->replaceTenant(
            $definition,
            $alpha['tenant_id'],
            $alpha['member_id'],
            'alpha-secret',
            $now,
            null,
            null,
            '*',
        );
        $admin->replaceTenant(
            $definition,
            $beta['tenant_id'],
            $beta['member_id'],
            'beta-secret',
            $now,
            null,
            null,
            '*',
        );
        $this->transplantSecret(
            'SELECT ciphertext, nonce, key_id FROM pa_setting_tenant_value WHERE tenant_id = ' . $alpha['tenant_id'],
            'UPDATE pa_setting_tenant_value SET ciphertext = :ciphertext, nonce = :nonce, key_id = :key_id'
                . ' WHERE tenant_id = ' . $beta['tenant_id'],
        );

        $this->expectSecretTransplantFailure(
            fn() => (new SettingResolver(
                $repository,
                $protector,
                new ArrayRevisionedSettingCache(),
            ))->resolveTenant($definition, $beta['tenant_id'], $now),
            'alpha-secret',
            'beta-secret',
        );
    }

    public function testSecretCiphertextCannotCrossDeploymentAndTenantScopes(): void
    {
        $registry = $this->registry([$this->secretDefinition([
            'allowed_scopes' => ['deployment', 'tenant'],
        ])]);
        $repository = $this->synchronize($registry);
        $definition = $registry->require('example.module', 'runtime-secret');
        $protector = $this->secretProtector();
        $tenant = $this->tenant('alpha');
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');
        $admin = new SettingAdminService($repository, $protector);
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
        $this->transplantSecret(
            'SELECT ciphertext, nonce, key_id FROM pa_setting_deployment_value',
            'UPDATE pa_setting_tenant_value SET ciphertext = :ciphertext, nonce = :nonce, key_id = :key_id'
                . ' WHERE tenant_id = ' . $tenant['tenant_id'],
        );

        $this->expectSecretTransplantFailure(
            fn() => (new SettingResolver(
                $repository,
                $protector,
                new ArrayRevisionedSettingCache(),
            ))->resolveTenant($definition, $tenant['tenant_id'], $now),
            'deployment-secret',
            'tenant-secret',
        );
    }

    public function testSecretCiphertextCannotCrossDefinitionIdentity(): void
    {
        $registry = $this->registry([
            $this->secretDefinition(['key' => 'donor-secret', 'name' => 'Donor secret']),
            $this->secretDefinition(['key' => 'recipient-secret', 'name' => 'Recipient secret']),
        ]);
        $repository = $this->synchronize($registry);
        $donor = $registry->require('example.module', 'donor-secret');
        $recipient = $registry->require('example.module', 'recipient-secret');
        $protector = $this->secretProtector();
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');
        $operatorId = $this->operator();
        $admin = new SettingAdminService($repository, $protector);
        $admin->replaceDeployment($donor, 'donor-value', $operatorId, $now, null, null, '*');
        $admin->replaceDeployment($recipient, 'recipient-value', $operatorId, $now, null, null, '*');
        $this->transplantSecret(
            <<<'SQL'
SELECT value_row.ciphertext, value_row.nonce, value_row.key_id
FROM pa_setting_deployment_value value_row
JOIN pa_setting_definition definition_row ON definition_row.id = value_row.definition_id
WHERE definition_row.setting_key = 'donor-secret'
SQL,
            <<<'SQL'
UPDATE pa_setting_deployment_value value_row
JOIN pa_setting_definition definition_row ON definition_row.id = value_row.definition_id
SET value_row.ciphertext = :ciphertext, value_row.nonce = :nonce, value_row.key_id = :key_id
WHERE definition_row.setting_key = 'recipient-secret'
SQL,
        );

        $this->expectSecretTransplantFailure(
            fn() => (new SettingResolver(
                $repository,
                $protector,
                new ArrayRevisionedSettingCache(),
            ))->resolveDeployment($recipient, $now),
            'donor-value',
            'recipient-value',
        );
    }

    public function testSecretCiphertextCannotCrossTargetIdentity(): void
    {
        $registry = $this->registry([$this->secretDefinition([
            'allowed_scopes' => ['target'],
            'target_resource_key' => 'example.project',
            'target_operation' => 'updateProjectSetting',
        ])], targets: [[
            'module_key' => 'example.module',
            'resource_key' => 'example.project',
            'operation' => 'updateProjectSetting',
            'target_cardinality' => 'one_required',
        ]]);
        $repository = $this->synchronize($registry);
        $definition = $registry->require('example.module', 'runtime-secret');
        $protector = $this->secretProtector();
        $tenant = $this->tenant('alpha');
        $operation = $this->operation('example.module', 'example.project', 'updateProjectSetting');
        $projectOne = $this->authorization($tenant, 'example.project', ['project-1'], $operation);
        $projectTwo = $this->authorization($tenant, 'example.project', ['project-2'], $operation);
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');
        $writer = new TargetSettingWriter($repository, $protector);
        $writer->replace($projectOne, $definition, 'project-one-secret', $now, null, null, '*');
        $writer->replace($projectTwo, $definition, 'project-two-secret', $now, null, null, '*');
        $this->transplantSecret(
            "SELECT ciphertext, nonce, key_id FROM pa_setting_target_value WHERE target_id = 'project-1'",
            "UPDATE pa_setting_target_value SET ciphertext = :ciphertext, nonce = :nonce, key_id = :key_id"
                . " WHERE target_id = 'project-2'",
        );

        $this->expectSecretTransplantFailure(
            fn() => (new SettingResolver(
                $repository,
                $protector,
                new ArrayRevisionedSettingCache(),
            ))->resolveTarget($definition, $projectTwo, $now),
            'project-one-secret',
            'project-two-secret',
        );
    }

    /** @return array{\PeanutAdmin\Settings\Definition\SettingDefinition, \PeanutAdmin\Settings\Persistence\PdoSettingRepository, SodiumSecretProtector} */
    private function runtime(): array
    {
        $registry = $this->registry([$this->definition()], targets: [[
            'module_key' => 'example.module',
            'resource_key' => 'example.project',
            'operation' => 'updateProjectSetting',
            'target_cardinality' => 'one_required',
        ]]);
        $protector = new SodiumSecretProtector([
            'runtime' => random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        ], 'runtime');

        return [
            $registry->require('example.module', 'display-mode'),
            $this->synchronize($registry),
            $protector,
        ];
    }

    /** @param array{tenant_id: int, member_id: int} $tenant
     * @param non-empty-list<string> $targetIds
     */
    private function authorization(
        array $tenant,
        string $targetResourceKey,
        array $targetIds,
        ExternalOperationDefinition $operation,
    ): AuthorizedExternalOperation {
        return $this->authorizeTarget($tenant, $targetResourceKey, $targetIds, $operation);
    }

    private function operation(
        string $moduleKey,
        string $resourceKey,
        string $operationId,
    ): ExternalOperationDefinition {
        return new ExternalOperationDefinition(
            $operationId,
            'PUT',
            '/api/example/v1/projects/{project_id}/setting',
            'tenant',
            $moduleKey,
            new PermissionRequirement('tenant', ['example.module.manage']),
            $resourceKey,
            'targets',
            'one_required',
            true,
            true,
        );
    }

    /** @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function secretDefinition(array $override = []): array
    {
        return array_merge([
            'key' => 'runtime-secret',
            'name' => 'Runtime secret',
            'description' => 'Runtime-only authenticated setting.',
            'schema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 128,
            ],
            'required' => false,
            'secret' => true,
            'allowed_scopes' => ['deployment'],
            'target_resource_key' => null,
            'target_operation' => null,
        ], $override);
    }

    private function secretProtector(): SodiumSecretProtector
    {
        return new SodiumSecretProtector([
            'runtime' => random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        ], 'runtime');
    }

    private function transplantSecret(string $selectSql, string $updateSql): void
    {
        $statement = $this->database->query($selectSql);
        self::assertNotFalse($statement);
        $material = $statement->fetch();
        self::assertIsArray($material);
        self::assertIsString($material['ciphertext'] ?? null);
        self::assertIsString($material['nonce'] ?? null);
        self::assertIsString($material['key_id'] ?? null);
        $statement = $this->database->prepare($updateSql);
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'ciphertext' => $material['ciphertext'],
            'nonce' => $material['nonce'],
            'key_id' => $material['key_id'],
        ]));
    }

    private function expectSecretTransplantFailure(callable $operation, string ...$plaintexts): void
    {
        try {
            $operation();
        } catch (SettingException $exception) {
            self::assertContains(
                $exception->errorCode,
                ['SETTING_SECRET_UNAVAILABLE', 'SETTING_STORED_VALUE_INVALID'],
            );
            foreach ($plaintexts as $plaintext) {
                self::assertStringNotContainsString($plaintext, $exception->getMessage());
            }

            return;
        }
        self::fail('Expected transplanted secret material to fail authentication.');
    }

    private function expectDenied(callable $operation): void
    {
        try {
            $operation();
        } catch (SettingException $exception) {
            self::assertSame('SETTING_TARGET_UNAUTHORIZED', $exception->errorCode);
            self::assertSame(404, $exception->httpStatus);

            return;
        }
        self::fail('Expected target authorization to fail closed.');
    }

    private function expectIntervalError(callable $operation): void
    {
        try {
            $operation();
        } catch (SettingException $exception) {
            self::assertSame('SETTING_INTERVAL_INVALID', $exception->errorCode);
            self::assertSame(422, $exception->httpStatus);

            return;
        }
        self::fail('Expected the sub-millisecond timestamp to be rejected.');
    }
}
