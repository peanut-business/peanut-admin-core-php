<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Host;

use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Api\RequestId;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\DataPermissionAdapter;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Authorization\PermissionRequirement;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Host\AtomicOperationAdapter;
use PeanutAdmin\Kernel\Host\AuthorizedExternalOperation;
use PeanutAdmin\Kernel\Host\ExternalHostConfiguration;
use PeanutAdmin\Kernel\Host\ExternalOperationDefinition;
use PeanutAdmin\Kernel\Host\ExternalOperationHost;
use PeanutAdmin\Kernel\Host\ExternalOperationRequest;
use PeanutAdmin\Kernel\Host\ExternalOperationResponse;
use PeanutAdmin\Kernel\Host\ModuleAvailabilityAdapter;
use PeanutAdmin\Kernel\Host\PermissionAdapter;
use PeanutAdmin\Kernel\Host\ProblemDetailsAdapter;
use PeanutAdmin\Kernel\Host\TrustedContextAdapter;
use PeanutAdmin\Kernel\Host\TypedTargetAdapter;
use PeanutAdmin\Kernel\Http\PermissionMiddleware;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleGuard;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

final class ExternalOperationHostTest extends TestCase
{
    public function testAuthorizedOperationCannotBeConstructedByAHandler(): void
    {
        $constructor = (new ReflectionClass(AuthorizedExternalOperation::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    public function testAuthorizedOperationExposesNoPublicIssuer(): void
    {
        self::assertFalse((new ReflectionClass(AuthorizedExternalOperation::class))->hasMethod(
            'issueFromExternalOperationHost',
        ));
    }

    public function testIdempotencyHashIncludesTypedTargets(): void
    {
        $now = new DateTimeImmutable('2026-07-19T00:00:00Z');
        $context = $this->context(10, 'req_fixture_hash');
        $request = static fn(string $targetId): ExternalOperationRequest => new ExternalOperationRequest(
            RequestId::fromHeader('req_fixture_hash'),
            $context,
            'POST',
            '/api/v1/fixture/records',
            ['name' => 'Same body'],
            [[
                'target_resource_key' => 'fixture.scope',
                'target_id' => $targetId,
                'target_role' => 'primary',
            ]],
            '01KPEANUT-R02-HASH-0001',
            $now,
            $now->modify('+1 hour'),
        );

        self::assertNotSame($request('1')->requestHash(), $request('2')->requestHash());
        self::assertSame($request('1')->requestHash(), $request('1')->requestHash());
    }

    public function testHostUsesTrustedTenantAndPermissionAnyBeforeQueryHandler(): void
    {
        $targetCalls = 0;
        $host = $this->host(['fixture.record.alternate'], $targetCalls);
        $operation = $this->readDefinition(new PermissionRequirement(
            'tenant',
            ['fixture.record.read', 'fixture.record.alternate'],
            'any',
        ));
        $request = $this->request($this->context(10, 'req_fixture_1001'), 'req_fixture_1001', [
            'tenant_id' => 999,
        ]);

        $response = $host->read($operation, $request, static function ($authorized) use ($operation): ExternalOperationResponse {
            self::assertSame($operation, $authorized->operation);
            self::assertInstanceOf(stdClass::class, $authorized->queryConstraint);
            self::assertInstanceOf(TenantContext::class, $authorized->context);
            self::assertSame(10, $authorized->context->tenantId);
            return new ExternalOperationResponse(200, ['tenant_id' => $authorized->context->tenantId]);
        });

        self::assertSame(200, $response->status);
        self::assertSame(['tenant_id' => 10], $response->body);
        self::assertSame(1, $targetCalls);
    }

    public function testPermissionAllDenialStopsTargetAndDomainExecution(): void
    {
        $targetCalls = 0;
        $handlerCalls = 0;
        $host = $this->host(['fixture.record.read'], $targetCalls);
        $operation = $this->readDefinition(new PermissionRequirement(
            'tenant',
            ['fixture.record.read', 'fixture.record.audit'],
            'all',
        ));

        $response = $host->read(
            $operation,
            $this->request($this->context(10, 'req_fixture_1002'), 'req_fixture_1002'),
            static function () use (&$handlerCalls): ExternalOperationResponse {
                ++$handlerCalls;
                return new ExternalOperationResponse(200, []);
            },
        );

        self::assertSame(403, $response->status);
        self::assertSame('AUTHZ_PERMISSION_DENIED', $response->body['code']);
        self::assertSame(0, $targetCalls);
        self::assertSame(0, $handlerCalls);
    }

    public function testMissingModuleAndAudienceMismatchFailBeforeHandler(): void
    {
        $targetCalls = 0;
        $handlerCalls = 0;
        $host = $this->host(['fixture.record.read'], $targetCalls);
        $operation = new ExternalOperationDefinition(
            'fixtureUnknownList',
            'GET',
            '/api/v1/fixture/unknown',
            'tenant',
            'fixture.unknown',
            new PermissionRequirement('tenant', ['fixture.record.read']),
        );
        $response = $host->read(
            $operation,
            $this->request($this->context(10, 'req_fixture_1003'), 'req_fixture_1003'),
            static function () use (&$handlerCalls): ExternalOperationResponse {
                ++$handlerCalls;
                return new ExternalOperationResponse(200, []);
            },
        );
        self::assertSame(404, $response->status);
        self::assertSame(0, $handlerCalls);

        $mismatch = $host->read(
            $this->readDefinition(new PermissionRequirement('tenant', ['fixture.record.read'])),
            $this->request(new stdClass(), 'req_fixture_1004'),
            static function () use (&$handlerCalls): ExternalOperationResponse {
                ++$handlerCalls;
                return new ExternalOperationResponse(200, []);
            },
        );
        self::assertSame(403, $mismatch->status);
        self::assertSame('AUDIENCE_MISMATCH', $mismatch->body['code']);
        self::assertSame(0, $handlerCalls);
    }

    public function testTrustedPlatformContextCannotBeReplacedByTenantContext(): void
    {
        $configuration = new ExternalHostConfiguration(
            new ModuleHostLayout('backend/app/Modules', 'FixtureHost\\App\\Modules', 'frontend/src/modules'),
            ['backend/app/Modules/Fixture/Record'],
            '/api/v1',
            '/api/platform/v1',
            'docs/api/openapi.yaml',
            'backend/route/openapi-generated.php',
            'packages/web/generated/api.d.ts',
            ['fixture-web'],
            'X-Request-ID',
        );
        $operation = new ExternalOperationDefinition(
            'fixturePlatformList',
            'GET',
            '/api/platform/v1/fixture/records',
            'platform',
            'fixture.record',
            new PermissionRequirement('platform', ['platform.fixture.read']),
        );
        $requestId = 'req_fixture_1005';
        $platform = PlatformContext::fromValidatedSession(new ValidatedPlatformSession(
            1,
            'platform_session_fixture',
            20,
            40,
            'fixture-web',
            new DateTimeImmutable('2026-07-19T00:00:00Z'),
        ), $requestId);
        $request = $this->platformRequest($platform, $requestId);

        self::assertSame($platform, (new TrustedContextAdapter($configuration))->require($operation, $request));

        try {
            (new TrustedContextAdapter($configuration))->require(
                $operation,
                $this->platformRequest($this->context(10, $requestId), $requestId),
            );
        } catch (ApiException $exception) {
            self::assertSame('AUDIENCE_MISMATCH', $exception->errorCode);
            return;
        }

        self::fail('Tenant context must not enter a platform operation.');
    }

    public function testCommandGuardRunsAfterAuthorizationAndBeforeAtomicExecution(): void
    {
        $targetCalls = 0;
        $handlerCalls = 0;
        $guardCalls = 0;
        $host = $this->host(['fixture.record.write'], $targetCalls);
        $operation = new ExternalOperationDefinition(
            'fixtureRecordCreate',
            'POST',
            '/api/v1/fixture/records',
            'tenant',
            'fixture.record',
            new PermissionRequirement('tenant', ['fixture.record.write']),
            atomicCommand: true,
        );
        $now = new DateTimeImmutable('2026-07-19T00:00:00Z');
        $request = new ExternalOperationRequest(
            RequestId::fromHeader('req_fixture_guard_1001'),
            $this->context(10, 'req_fixture_guard_1001'),
            'POST',
            '/api/v1/fixture/records',
            [],
            [],
            null,
            $now,
            $now->modify('+1 hour'),
        );

        $response = $host->command(
            $operation,
            $request,
            static function () use (&$handlerCalls): never {
                ++$handlerCalls;
                throw new \LogicException('The domain handler must not run after a failed command guard.');
            },
            guard: static function (
                AuthorizedExternalOperation $authorized,
                ExternalOperationRequest $guardedRequest,
                PDO $transaction,
            ) use (&$guardCalls, $operation, $request): never {
                ++$guardCalls;
                self::assertSame($operation, $authorized->operation);
                self::assertSame($request, $guardedRequest);
                self::assertTrue($transaction->inTransaction());
                throw new ApiException('FIXTURE_UNAVAILABLE', 404, 'The fixture is unavailable.');
            },
        );

        self::assertSame(404, $response->status);
        self::assertSame('FIXTURE_UNAVAILABLE', $response->body['code']);
        self::assertSame(1, $guardCalls);
        self::assertSame(0, $handlerCalls);
        self::assertSame(0, $targetCalls);
    }

    /** @param list<string> $permissionKeys */
    private function host(array $permissionKeys, int &$targetCalls): ExternalOperationHost
    {
        $configuration = new ExternalHostConfiguration(
            new ModuleHostLayout('backend/app/Modules', 'FixtureHost\\App\\Modules', 'frontend/src/modules'),
            ['backend/app/Modules/Fixture/Record'],
            '/api/v1',
            '/api/platform/v1',
            'docs/api/openapi.yaml',
            'backend/route/openapi-generated.php',
            'packages/web/generated/api.d.ts',
            ['fixture-web'],
            'X-Request-ID',
        );
        $registry = new CompiledModuleRegistry([
            ManifestDocument::fromArray('backend/app/Modules/Fixture/Record', ['key' => 'fixture.record']),
        ], [], [], [], 'fixture-revision');
        $moduleRepository = new class implements ModuleRuntimeRepository {
            public function installation(string $moduleKey): ModuleInstallationRecord
            {
                return new ModuleInstallationRecord($moduleKey, '1.0.0', 'active', 1, 'digest');
            }

            public function tenantModule(int $tenantId, string $moduleKey): TenantModuleRecord
            {
                return new TenantModuleRecord($tenantId, $moduleKey, 'enabled', null, null, 1);
            }

            public function enabledDependents(int $tenantId, string $moduleKey): array
            {
                return [];
            }
        };
        $tenantRepository = new class ($permissionKeys) implements TenantAuthorizationRepository {
            /** @param list<string> $permissionKeys */
            public function __construct(private array $permissionKeys) {}
            public function member(int $tenantId, int $memberId): ?array
            {
                return null;
            }
            public function activeRoles(int $tenantId, int $memberId): array
            {
                return [];
            }
            public function revision(int $tenantId, int $memberId): string
            {
                return '1';
            }
            public function permissions(int $tenantId, int $memberId): EffectivePermissionSet
            {
                return new EffectivePermissionSet($this->permissionKeys);
            }
        };
        $platformRepository = new class implements PlatformAuthorizationRepository {
            public function revision(int $operatorId): string
            {
                return '1';
            }
            public function permissions(int $operatorId): EffectivePermissionSet
            {
                return new EffectivePermissionSet([]);
            }
        };
        $permissionMiddleware = new PermissionMiddleware(
            new TenantAuthorizationEvaluator($tenantRepository, new RevisionPermissionCache()),
            new PlatformAuthorizationEvaluator($platformRepository, new RevisionPermissionCache()),
        );
        $dataPermission = new DataPermissionAdapter(
            static function () use (&$targetCalls): object {
                ++$targetCalls;
                return new stdClass();
            },
            static function () use (&$targetCalls): void {
                ++$targetCalls;
            },
        );

        return new ExternalOperationHost(
            $configuration,
            new TrustedContextAdapter($configuration),
            new ModuleAvailabilityAdapter($registry, new ModuleGuard($moduleRepository)),
            new PermissionAdapter($permissionMiddleware),
            new TypedTargetAdapter($dataPermission),
            new AtomicOperationAdapter(new PDO('sqlite::memory:')),
            new ProblemDetailsAdapter(),
        );
    }

    private function readDefinition(PermissionRequirement $permission): ExternalOperationDefinition
    {
        return new ExternalOperationDefinition(
            'fixtureRecordsList',
            'GET',
            '/api/v1/fixture/records',
            'tenant',
            'fixture.record',
            $permission,
            'fixture.record',
            'query',
            'many_readable',
        );
    }

    /** @param array<string, mixed> $body */
    private function request(mixed $context, string $requestId, array $body = []): ExternalOperationRequest
    {
        $now = new DateTimeImmutable('2026-07-19T00:00:00Z');
        return new ExternalOperationRequest(
            RequestId::fromHeader($requestId),
            $context,
            'GET',
            '/api/v1/fixture/records',
            $body,
            [],
            null,
            $now,
            $now->modify('+1 hour'),
        );
    }

    private function context(int $tenantId, string $requestId): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            'session_fixture',
            $tenantId,
            20,
            30,
            'fixture-web',
            new DateTimeImmutable('2026-07-19T00:00:00Z'),
            1,
        ), $requestId);
    }

    private function platformRequest(mixed $context, string $requestId): ExternalOperationRequest
    {
        $now = new DateTimeImmutable('2026-07-19T00:00:00Z');
        return new ExternalOperationRequest(
            RequestId::fromHeader($requestId),
            $context,
            'GET',
            '/api/platform/v1/fixture/records',
            [],
            [],
            null,
            $now,
            $now->modify('+1 hour'),
        );
    }
}
