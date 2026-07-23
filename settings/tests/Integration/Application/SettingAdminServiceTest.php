<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Tests\Integration\Application;

use DateTimeImmutable;
use PeanutAdmin\Settings\Application\EffectiveSetting;
use PeanutAdmin\Settings\Application\SettingAdminService;
use PeanutAdmin\Settings\Application\SettingException;
use PeanutAdmin\Settings\Persistence\PdoSettingRepository;
use PeanutAdmin\Settings\Secret\SodiumSecretProtector;
use PeanutAdmin\Settings\Tests\Integration\Support\SettingsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/SettingsDatabaseTestCase.php';

final class SettingAdminServiceTest extends SettingsDatabaseTestCase
{
    public function testSynchronizesDefinitionsIdempotentlyUpdatesDigestsAndRetiresRemovedKeys(): void
    {
        $registry = $this->registry([
            $this->plainDefinition('alpha-setting'),
            $this->plainDefinition('beta-setting'),
        ]);
        $repository = $this->synchronize($registry);

        self::assertSame(['inserted' => 0, 'updated' => 0, 'retired' => 0], $repository->synchronize(
            $registry,
            new DateTimeImmutable('2026-07-19T08:01:00Z'),
        ));

        $changed = $this->registry([
            $this->plainDefinition('alpha-setting', ['description' => 'Changed trusted definition.']),
        ]);
        self::assertSame(['inserted' => 0, 'updated' => 1, 'retired' => 1], $repository->synchronize(
            $changed,
            new DateTimeImmutable('2026-07-19T08:02:00Z'),
        ));
        self::assertSame(2, (int) $this->scalar(
            "SELECT revision FROM pa_setting_definition WHERE setting_key = 'alpha-setting'",
        ));
        self::assertSame('retired', $this->scalar(
            "SELECT status FROM pa_setting_definition WHERE setting_key = 'beta-setting'",
        ));
    }

    public function testAssertCurrentDefinitionAcceptsActiveAndRejectsDigestMismatchAndRetired(): void
    {
        $registry = $this->registry([$this->plainDefinition('display-mode')]);
        $repository = $this->synchronize($registry);
        $definition = $registry->require('example.module', 'display-mode');

        $repository->assertCurrentDefinition($definition);
        $repository->assertCurrentDefinition($definition, true);

        $changedRegistry = $this->registry([
            $this->plainDefinition('display-mode', ['description' => 'Changed trusted definition.']),
        ]);
        $this->expectSettingError(
            'SETTING_NOT_FOUND',
            fn() => $repository->assertCurrentDefinition(
                $changedRegistry->require('example.module', 'display-mode'),
            ),
            404,
        );

        $replacementRegistry = $this->registry([$this->plainDefinition('replacement-setting')]);
        $repository->synchronize(
            $replacementRegistry,
            new DateTimeImmutable('2026-07-19T08:01:00Z'),
        );
        $this->expectSettingError(
            'SETTING_NOT_FOUND',
            fn() => $repository->assertCurrentDefinition($definition),
            404,
        );
    }

    public function testDefinitionMutationWaitsForTheSharedLockHeldThroughValueCommit(): void
    {
        $registry = $this->registry([$this->plainDefinition('display-mode')]);
        $repository = $this->synchronize($registry);
        $definition = $registry->require('example.module', 'display-mode');
        $operatorId = $this->operator();
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');
        (new SettingAdminService($repository, $this->protector()))->replaceDeployment(
            $definition,
            'compact',
            $operatorId,
            $now,
            null,
            null,
            '*',
        );

        $writerConnection = $this->additionalDatabaseConnection();
        $writerConnection->beginTransaction();
        (new PdoSettingRepository($writerConnection))->writeDeployment(
            $definition,
            'set',
            ['value_json' => '"comfortable"', 'ciphertext' => null, 'nonce' => null, 'key_id' => null],
            $operatorId,
            $now,
            null,
            '"rev-1"',
            null,
        );
        $changed = $this->registry([
            $this->plainDefinition('display-mode', ['description' => 'Changed during a value write.']),
        ]);
        $mutatorConnection = $this->additionalDatabaseConnection();
        $mutatorConnection->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $mutator = new PdoSettingRepository($mutatorConnection);

        try {
            try {
                $mutator->synchronize($changed, new DateTimeImmutable('2026-07-19T08:01:00Z'));
                self::fail('Definition synchronization crossed an uncommitted settings value update.');
            } catch (\PDOException $exception) {
                self::assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
            }
            self::assertSame(1, (int) $this->scalar('SELECT revision FROM pa_setting_deployment_value'));
            $writerConnection->commit();
            self::assertSame(
                ['inserted' => 0, 'updated' => 1, 'retired' => 0],
                $mutator->synchronize($changed, new DateTimeImmutable('2026-07-19T08:01:00Z')),
            );
            self::assertSame(2, (int) $this->scalar('SELECT revision FROM pa_setting_deployment_value'));
            self::assertSame(2, (int) $this->scalar('SELECT revision FROM pa_setting_definition'));
        } finally {
            if ($writerConnection->inTransaction()) {
                $writerConnection->rollBack();
            }
        }
    }

    public function testDeploymentMutationsRequireCreationAndReplacementPreconditions(): void
    {
        $registry = $this->registry([$this->plainDefinition('display-mode')]);
        $repository = $this->synchronize($registry);
        $service = new SettingAdminService($repository, $this->protector());
        $definition = $registry->require('example.module', 'display-mode');
        $operatorId = $this->operator();
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');

        $this->expectSettingError('PRECONDITION_REQUIRED', fn() => $service->replaceDeployment(
            $definition,
            'compact',
            $operatorId,
            $now,
            null,
            null,
            null,
        ));
        $created = $service->replaceDeployment(
            $definition,
            'compact',
            $operatorId,
            $now,
            null,
            null,
            '*',
        );
        self::assertSame('"rev-1"', $created->etag);
        self::assertSame('compact', $created->value);
        self::assertSame('deployment', $created->source);

        $this->expectSettingError('SETTING_REVISION_MISMATCH', fn() => $service->replaceDeployment(
            $definition,
            'comfortable',
            $operatorId,
            $now,
            null,
            null,
            '*',
        ), 412);
        $this->expectSettingError('PRECONDITION_REQUIRED', fn() => $service->replaceDeployment(
            $definition,
            'comfortable',
            $operatorId,
            $now,
            null,
            null,
            null,
        ));
        $this->expectSettingError('SETTING_REVISION_MISMATCH', fn() => $service->replaceDeployment(
            $definition,
            'comfortable',
            $operatorId,
            $now,
            null,
            '"rev-99"',
            null,
        ));

        $replaced = $service->replaceDeployment(
            $definition,
            'comfortable',
            $operatorId,
            $now,
            null,
            '"rev-1"',
            null,
        );
        self::assertSame('"rev-2"', $replaced->etag);
        self::assertSame('comfortable', $replaced->value);

        $unset = $service->unsetDeployment($definition, $operatorId, $now, '"rev-2"');
        self::assertSame('"rev-3"', $unset->etag);
        self::assertFalse($unset->configured);
        self::assertSame('default', $unset->source);
        self::assertSame('comfortable', $unset->value);
    }

    public function testSecondConnectionExistingCreateAndStaleWriteRemainRevisionMismatch(): void
    {
        $registry = $this->registry([$this->plainDefinition('display-mode')]);
        $repository = $this->synchronize($registry);
        $definition = $registry->require('example.module', 'display-mode');
        $operatorId = $this->operator();
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');
        $first = new SettingAdminService($repository, $this->protector());
        $second = new SettingAdminService(
            new PdoSettingRepository($this->additionalDatabaseConnection()),
            $this->protector(),
        );

        $first->replaceDeployment($definition, 'compact', $operatorId, $now, null, null, '*');
        $this->expectSettingError(
            'SETTING_REVISION_MISMATCH',
            fn() => $second->replaceDeployment(
                $definition,
                'comfortable',
                $operatorId,
                $now,
                null,
                null,
                '*',
            ),
            412,
        );
        $this->expectSettingError(
            'SETTING_REVISION_MISMATCH',
            fn() => $second->replaceDeployment(
                $definition,
                'comfortable',
                $operatorId,
                $now,
                null,
                '"rev-99"',
                null,
            ),
            412,
        );
    }

    public function testDeploymentAndTenantWritesReturnEffectiveFallbackWithManagedPreconditions(): void
    {
        $registry = $this->registry([$this->plainDefinition('display-mode')]);
        $repository = $this->synchronize($registry);
        $service = new SettingAdminService($repository, $this->protector());
        $definition = $registry->require('example.module', 'display-mode');
        $operatorId = $this->operator();
        $tenant = $this->tenant('alpha');
        $asOf = new DateTimeImmutable('2026-07-19T08:00:00Z');
        $activeAt = new DateTimeImmutable('2026-07-19T07:00:00Z');
        $futureAt = new DateTimeImmutable('2026-07-19T09:00:00Z');

        $service->replaceDeployment(
            $definition,
            'compact',
            $operatorId,
            $activeAt,
            null,
            null,
            '*',
            $asOf,
        );
        $futureDeployment = $service->replaceDeployment(
            $definition,
            'comfortable',
            $operatorId,
            $futureAt,
            null,
            '"rev-1"',
            null,
            $asOf,
        );
        self::assertSame([
            'configured' => false,
            'source_scope' => 'default',
            'value' => 'comfortable',
            'effective_at' => null,
            'expires_at' => null,
            'revision' => '2',
            'etag' => '"rev-2"',
        ], $this->webWriteShape($futureDeployment));

        $unsetDeployment = $service->unsetDeployment(
            $definition,
            $operatorId,
            $asOf,
            '"rev-2"',
            $asOf,
        );
        self::assertSame('default', $unsetDeployment->source);
        self::assertFalse($unsetDeployment->configured);
        self::assertSame(3, $unsetDeployment->revision);
        self::assertSame('"rev-3"', $unsetDeployment->etag);

        $service->replaceDeployment(
            $definition,
            'compact',
            $operatorId,
            $activeAt,
            null,
            '"rev-3"',
            null,
            $asOf,
        );
        $futureTenant = $service->replaceTenant(
            $definition,
            $tenant['tenant_id'],
            $tenant['member_id'],
            'comfortable',
            $futureAt,
            null,
            null,
            '*',
            $asOf,
        );
        self::assertSame([
            'configured' => true,
            'source_scope' => 'deployment',
            'value' => 'compact',
            'effective_at' => '2026-07-19T07:00:00.000Z',
            'expires_at' => null,
            'revision' => '1',
            'etag' => '"rev-1"',
        ], $this->webWriteShape($futureTenant));

        $unsetTenant = $service->unsetTenant(
            $definition,
            $tenant['tenant_id'],
            $tenant['member_id'],
            $asOf,
            '"rev-1"',
            $asOf,
        );
        self::assertSame([
            'configured' => true,
            'source_scope' => 'deployment',
            'value' => 'compact',
            'effective_at' => '2026-07-19T07:00:00.000Z',
            'expires_at' => null,
            'revision' => '2',
            'etag' => '"rev-2"',
        ], $this->webWriteShape($unsetTenant));
    }

    public function testRepeatableReadSameKeyCompetitionMapsToConflictStably(): void
    {
        self::assertTrue(function_exists('proc_open'), 'B03 integration tests require proc_open.');
        $registry = $this->registry([
            $this->plainDefinition('race-setting-1'),
            $this->plainDefinition('race-setting-2'),
            $this->plainDefinition('race-setting-3'),
        ]);
        $this->synchronize($registry);
        $operatorId = $this->operator();
        $workerPath = tempnam(sys_get_temp_dir(), 'peanut-settings-race-');
        self::assertIsString($workerPath);
        self::assertNotFalse(file_put_contents($workerPath, $this->competitionWorker()));
        try {
            for ($attempt = 1; $attempt <= 3; ++$attempt) {
                $definition = $registry->require('example.module', 'race-setting-' . $attempt);
                $command = [
                    PHP_BINARY,
                    $workerPath,
                    dirname(__DIR__, 6),
                    (string) $this->requiredPort('DB_PORT'),
                    (string) (getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev'),
                    self::DATABASE,
                    (string) $operatorId,
                    base64_encode(serialize($definition)),
                ];
                $workers = [
                    $this->startCompetitionWorker($command),
                    $this->startCompetitionWorker($command),
                ];

                try {
                    foreach ($workers as $worker) {
                        self::assertSame(3, fwrite($worker['pipes'][0], "go\n"));
                        fflush($worker['pipes'][0]);
                    }
                    $outcomes = array_map($this->finishCompetitionWorker(...), $workers);

                    self::assertCount(1, array_filter(
                        $outcomes,
                        static fn(array $outcome): bool => ($outcome['kind'] ?? null) === 'ok',
                    ));
                    self::assertCount(1, array_filter(
                        $outcomes,
                        static fn(array $outcome): bool => ($outcome['kind'] ?? null) === 'setting'
                            && ($outcome['code'] ?? null) === 'SETTING_VALUE_CONFLICT'
                            && ($outcome['status'] ?? null) === 409,
                    ), json_encode($outcomes, JSON_THROW_ON_ERROR));
                } finally {
                    foreach ($workers as $worker) {
                        $this->closeCompetitionWorker($worker);
                    }
                }
            }
            self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM pa_setting_deployment_value'));
        } finally {
            @unlink($workerPath);
        }
    }

    public function testTenantMutationsAreTenantScopedAndValidateSchemaAndIntervals(): void
    {
        $registry = $this->registry([$this->plainDefinition('display-mode')]);
        $repository = $this->synchronize($registry);
        $service = new SettingAdminService($repository, $this->protector());
        $definition = $registry->require('example.module', 'display-mode');
        $alpha = $this->tenant('alpha');
        $beta = $this->tenant('beta');
        $now = new DateTimeImmutable('2026-07-19T08:00:00Z');

        $this->expectSettingError('SETTING_VALUE_INVALID', fn() => $service->replaceTenant(
            $definition,
            $alpha['tenant_id'],
            $alpha['member_id'],
            'unsupported',
            $now,
            null,
            null,
            '*',
        ));
        $this->expectSettingError('SETTING_INTERVAL_INVALID', fn() => $service->replaceTenant(
            $definition,
            $alpha['tenant_id'],
            $alpha['member_id'],
            'compact',
            $now,
            $now,
            null,
            '*',
        ));
        $subMillisecond = new DateTimeImmutable('2026-07-19T08:00:00.123456Z');
        $this->expectSettingError('SETTING_INTERVAL_INVALID', fn() => $service->replaceTenant(
            $definition,
            $alpha['tenant_id'],
            $alpha['member_id'],
            'compact',
            $subMillisecond,
            null,
            null,
            '*',
        ));
        $this->expectSettingError('SETTING_INTERVAL_INVALID', fn() => $service->unsetTenant(
            $definition,
            $alpha['tenant_id'],
            $alpha['member_id'],
            $subMillisecond,
            null,
        ));

        $alphaValue = $service->replaceTenant(
            $definition,
            $alpha['tenant_id'],
            $alpha['member_id'],
            'compact',
            $now,
            null,
            null,
            '*',
        );
        $betaValue = $service->replaceTenant(
            $definition,
            $beta['tenant_id'],
            $beta['member_id'],
            'comfortable',
            $now,
            null,
            null,
            '*',
        );

        self::assertSame('compact', $alphaValue->value);
        self::assertSame('comfortable', $betaValue->value);
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM pa_setting_tenant_value'));
    }

    public function testDeploymentAndDirectRepositoryWritesRejectSubMillisecondTimestamps(): void
    {
        $registry = $this->registry([$this->plainDefinition('display-mode')]);
        $repository = $this->synchronize($registry);
        $service = new SettingAdminService($repository, $this->protector());
        $definition = $registry->require('example.module', 'display-mode');
        $operatorId = $this->operator();
        $exact = new DateTimeImmutable('2026-07-19T08:00:00.123000Z');
        $subMillisecond = new DateTimeImmutable('2026-07-19T08:00:00.123456Z');

        $this->expectSettingError('SETTING_INTERVAL_INVALID', fn() => $service->replaceDeployment(
            $definition,
            'compact',
            $operatorId,
            $subMillisecond,
            null,
            null,
            '*',
        ));
        $this->expectSettingError('SETTING_INTERVAL_INVALID', fn() => $service->replaceDeployment(
            $definition,
            'compact',
            $operatorId,
            $exact,
            $subMillisecond->modify('+1 second'),
            null,
            '*',
        ));
        $this->expectSettingError('SETTING_INTERVAL_INVALID', fn() => $service->unsetDeployment(
            $definition,
            $operatorId,
            $subMillisecond,
            null,
        ));
        $this->expectSettingError('SETTING_INTERVAL_INVALID', fn() => $repository->writeDeployment(
            $definition,
            'set',
            ['value_json' => '"compact"', 'ciphertext' => null, 'nonce' => null, 'key_id' => null],
            $operatorId,
            $subMillisecond,
            null,
            null,
            '*',
        ));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pa_setting_deployment_value'));
    }

    public function testSecretMutationPersistsOnlyAuthenticatedCiphertextAndReturnsRedactedState(): void
    {
        $registry = $this->registry([$this->secretDefinition()]);
        $repository = $this->synchronize($registry);
        $service = new SettingAdminService($repository, $this->protector());
        $definition = $registry->require('example.module', 'api-token');
        $operatorId = $this->operator();

        $result = $service->replaceDeployment(
            $definition,
            'runtime-only-token',
            $operatorId,
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            null,
            null,
            '*',
        );
        $statement = $this->database->query(
            'SELECT value_json, ciphertext, nonce, key_id FROM pa_setting_deployment_value',
        );
        self::assertNotFalse($statement);
        $row = $statement->fetch();

        self::assertIsArray($row);
        self::assertNull($row['value_json']);
        self::assertNotSame('', $row['ciphertext']);
        self::assertStringNotContainsString('runtime-only-token', (string) $row['ciphertext']);
        self::assertSame(24, strlen((string) $row['nonce']));
        self::assertSame('runtime', $row['key_id']);
        self::assertSame([
            'module_key' => 'example.module',
            'setting_key' => 'api-token',
            'configured' => true,
            'source' => 'deployment',
            'revision' => 1,
            'etag' => '"rev-1"',
            'effective_at' => '2026-07-19T08:00:00.000Z',
            'expires_at' => null,
        ], $result->toAdminArray());
        self::assertNull($result->value);
    }

    /** @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function plainDefinition(string $key, array $override = []): array
    {
        return array_merge([
            'key' => $key,
            'name' => ucfirst(str_replace('-', ' ', $key)),
            'description' => 'Generic non-secret setting.',
            'schema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'string',
                'enum' => ['compact', 'comfortable'],
            ],
            'required' => false,
            'secret' => false,
            'allowed_scopes' => ['deployment', 'tenant'],
            'target_resource_key' => null,
            'target_operation' => null,
            'default' => 'comfortable',
        ], $override);
    }

    /** @return array<string, mixed> */
    private function secretDefinition(): array
    {
        return [
            'key' => 'api-token',
            'name' => 'API token',
            'description' => 'Generic integration secret.',
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
        ];
    }

    private function protector(): SodiumSecretProtector
    {
        return new SodiumSecretProtector([
            'runtime' => random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        ], 'runtime');
    }

    /** @return array{configured: bool, source_scope: string|null, value: mixed, effective_at: string|null, expires_at: string|null, revision: string, etag: string|null} */
    private function webWriteShape(EffectiveSetting $setting): array
    {
        return [
            'configured' => $setting->configured,
            'source_scope' => $setting->source,
            'value' => $setting->value,
            'effective_at' => $setting->effectiveAt,
            'expires_at' => $setting->expiresAt,
            'revision' => (string) $setting->revision,
            'etag' => $setting->etag,
        ];
    }

    /** @param list<string> $command
     * @return array{process: resource, pipes: array<int, resource>, connection_id: positive-int}
     */
    private function startCompetitionWorker(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        foreach ($pipes as $pipe) {
            stream_set_timeout($pipe, 10);
        }
        $line = fgets($pipes[1]);
        if (!is_string($line)) {
            self::fail('Competition worker failed before its barrier: ' . stream_get_contents($pipes[2]));
        }
        $ready = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($ready);
        self::assertSame('ready', $ready['kind'] ?? null);
        self::assertSame('REPEATABLE-READ', $ready['isolation'] ?? null);
        $connectionId = filter_var($ready['connection_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($connectionId) || $connectionId < 1) {
            self::fail('Competition worker returned an invalid connection ID.');
        }

        return ['process' => $process, 'pipes' => $pipes, 'connection_id' => $connectionId];
    }

    /** @param array{process: resource, pipes: array<int, resource>, connection_id: positive-int} $worker
     * @return array<string, mixed>
     */
    private function finishCompetitionWorker(array $worker): array
    {
        $line = fgets($worker['pipes'][1]);
        self::assertIsString($line, stream_get_contents($worker['pipes'][2]));
        $outcome = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($outcome);

        return $outcome;
    }

    /** @param array{process: resource, pipes: array<int, resource>, connection_id: positive-int} $worker */
    private function closeCompetitionWorker(array $worker): void
    {
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($worker['process'])) {
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                proc_terminate($worker['process']);
            }
            proc_close($worker['process']);
        }
    }

    private function competitionWorker(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use PeanutAdmin\Settings\Application\SettingException;
use PeanutAdmin\Settings\Definition\SettingDefinition;
use PeanutAdmin\Settings\Persistence\PdoSettingRepository;

require $argv[1] . '/vendor/autoload.php';

$pdo = new \PDO(
    sprintf('mysql:host=127.0.0.1;port=%d;dbname=%s;charset=utf8mb4', (int) $argv[2], $argv[4]),
    'root',
    $argv[3],
    [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
$definition = unserialize(
    base64_decode($argv[6], true),
    ['allowed_classes' => [SettingDefinition::class]],
);
if (!$definition instanceof SettingDefinition) {
    throw new \RuntimeException('The serialized setting definition is invalid.');
}
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
$isolation = $pdo->query('SELECT @@transaction_isolation')->fetchColumn();
$connectionId = $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$pdo->beginTransaction();
$definitionId = $pdo->prepare(<<<'SQL'
SELECT id FROM pa_setting_definition
WHERE module_key = :module_key AND setting_key = :setting_key AND status = 'active'
FOR SHARE
SQL);
$definitionId->execute([
    'module_key' => $definition->moduleKey,
    'setting_key' => $definition->key,
]);
$definitionRow = $definitionId->fetch();
if (!is_array($definitionRow)) {
    throw new \RuntimeException('The setting definition is unavailable to the race worker.');
}
$gap = $pdo->prepare(<<<'SQL'
SELECT id FROM pa_setting_deployment_value
WHERE definition_id = :definition_id
FOR UPDATE
SQL);
$gap->execute(['definition_id' => (int) $definitionRow['id']]);
if ($gap->fetch() !== false) {
    throw new \RuntimeException('The race worker expected an empty settings value key.');
}
fwrite(STDOUT, json_encode([
    'kind' => 'ready',
    'connection_id' => (int) $connectionId,
    'isolation' => (string) $isolation,
], JSON_THROW_ON_ERROR) . "\n");
fflush(STDOUT);
if (fgets(STDIN) !== "go\n") {
    throw new \RuntimeException('The competition barrier was not released.');
}

try {
    (new PdoSettingRepository($pdo))->writeDeployment(
        $definition,
        'set',
        ['value_json' => '"comfortable"', 'ciphertext' => null, 'nonce' => null, 'key_id' => null],
        (int) $argv[5],
        new \DateTimeImmutable('2026-07-19T08:00:00Z'),
        null,
        null,
        '*',
    );
    $pdo->commit();
    $outcome = ['kind' => 'ok'];
} catch (SettingException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $outcome = [
        'kind' => 'setting',
        'code' => $exception->errorCode,
        'status' => $exception->httpStatus,
    ];
} catch (\Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $outcome = ['kind' => 'throwable', 'exception' => $exception::class];
}
fwrite(STDOUT, json_encode($outcome, JSON_THROW_ON_ERROR) . "\n");
fflush(STDOUT);
PHP;
    }

    private function expectSettingError(string $code, callable $operation, ?int $status = null): void
    {
        try {
            $operation();
        } catch (SettingException $exception) {
            self::assertSame($code, $exception->errorCode);
            if ($status !== null) {
                self::assertSame($status, $exception->httpStatus);
            }

            return;
        }
        self::fail("Expected settings error {$code}.");
    }
}
