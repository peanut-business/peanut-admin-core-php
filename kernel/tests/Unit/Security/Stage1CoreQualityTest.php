<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Security;

use DateTimeImmutable;
use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Policy\EffectiveCondition;
use PeanutAdmin\DataPermission\Policy\EffectiveConditionGroup;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;
use PeanutAdmin\DataPermission\Provider\DepartmentHierarchyProvider;
use PeanutAdmin\DataPermission\Provider\ProviderColumnMap;
use PeanutAdmin\DataPermission\Provider\StandardResourcePolicyProvider;
use PeanutAdmin\DataPermission\Provider\TargetSetMembershipProvider;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\Kernel\Api\RequestId;
use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Host\ApplicationHostPolicy;
use PeanutAdmin\Kernel\Host\ProblemDetailsAdapter;
use PeanutAdmin\Kernel\Persistence\Model\TenantModel;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class Stage1CoreQualityTest extends TestCase
{
    public function testTenantModelAttributesRejectWeakTypeAndPreventOverwrite(): void
    {
        $scope = TenantScope::fromTrustedContext(7, 'test');

        $attrs = TenantModel::tenantAttributes($scope, ['tenant_id' => '7', 'name' => 'demo']);
        self::assertSame(7, $attrs['tenant_id']);
        self::assertSame('demo', $attrs['name']);

        foreach (['7abc', true, 8] as $badTenantId) {
            try {
                TenantModel::tenantAttributes($scope, ['tenant_id' => $badTenantId]);
                self::fail('Expected DomainException for invalid tenant_id');
            } catch (\DomainException $exception) {
                self::assertSame('TENANT_WRITE_OWNERSHIP_MISMATCH', $exception->getMessage());
            }
        }
    }

    public function testTrustedEnvelopeCodecRejectsUnsupportedVersion(): void
    {
        $signingKey = str_repeat('a', 32);
        $codec = new TrustedEnvelopeCodec($signingKey);
        $payload = [
            'version' => 2,
            'tenant_id' => 1,
            'account_id' => 2,
            'member_id' => 3,
            'resource_key' => 'core.demo',
            'operation' => 'read',
            'requested_targets' => [],
            'operation_id' => 'op-1',
            'trace_id' => 'trace-1',
        ];
        $canonical = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $encoded = json_encode([
            'payload' => $payload,
            'signature' => hash_hmac('sha256', $canonical, $signingKey),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->expectException(AuthException::class);
        $codec->verify($encoded);
    }

    public function testApplicationHostPolicyNormalizesPlatformHosts(): void
    {
        $policy = new ApplicationHostPolicy(
            'multi-tenant',
            [' PLATFORM.EXAMPLE.COM '],
            new TenantEntryBindingResolver(static fn(): array => []),
        );

        $request = new class {
            public function host(): string
            {
                return 'platform.example.com';
            }
        };

        $policy->assertPlatform($request);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('TENANT_ADMIN_HOST_FORBIDDEN');
        $policy->assertTenantAdmin($request);
    }

    public function testProblemDetailsAdapterLogsUnexpectedInternalError(): void
    {
        $records = [];
        $logger = new class ($records) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<mixed>}> $records */
            public function __construct(public array &$records) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $adapter = new ProblemDetailsAdapter($logger);
        $requestId = RequestId::fromHeader('req_0123456789abcdef0123456789abcdef');
        $response = $adapter->respond(new \RuntimeException('boom'), $requestId);

        self::assertSame(500, $response->status);
        self::assertCount(1, $records);
        self::assertSame('error', $records[0]['level']);
        self::assertSame('EXTERNAL_OPERATION_INTERNAL_ERROR', $records[0]['message']);
        self::assertSame($requestId->value, $records[0]['context']['request_id']);
    }

    public function testStandardResourcePolicyProviderDeduplicatesWithinSingleDecision(): void
    {
        $deptCalls = 0;
        $departments = new class ($deptCalls) implements DepartmentHierarchyProvider {
            public function __construct(public int &$calls) {}

            public function descendantsIncludingSelf(int $tenantId, int $departmentId): array
            {
                $this->calls++;
                return [$departmentId, $departmentId + 1];
            }
        };
        $targetSetCalls = 0;
        $targetSets = new class ($targetSetCalls) implements TargetSetMembershipProvider {
            public function __construct(public int &$calls) {}

            public function containsAll(int $tenantId, int $targetSetId, array $targetIds): bool
            {
                $this->calls++;
                return true;
            }
        };

        $provider = new StandardResourcePolicyProvider(
            new ProviderColumnMap(
                new ColumnReference('resource.tenant_id'),
                new ColumnReference('resource.owner_id'),
                new ColumnReference('resource.dept_id'),
                ['core.department' => new ColumnReference('resource.dept_id')],
            ),
            $departments,
            $targetSets,
        );
        $context = new AuthorizationContext(
            TenantContext::fromValidatedSession(
                new ValidatedTenantSession(
                    1,
                    'session',
                    10,
                    20,
                    30,
                    'admin-web',
                    new DateTimeImmutable('2026-07-16T10:00:00Z'),
                    1,
                    1,
                ),
                'request-stage1-memo',
            ),
            10,
        );
        $operation = new ResourceOperation(
            1,
            1,
            'core.demo',
            'core',
            'provider',
            'tenant',
            'list',
            'explicit_targets',
            'many',
            'all',
            ['core.demo.read'],
            [],
        );

        $policies = new EffectivePolicySet(
            [
                new EffectiveConditionGroup(1, 100, 1, [new EffectiveCondition(1, 'core.department_tree', null, null, [], 0)]),
                new EffectiveConditionGroup(2, 100, 2, [new EffectiveCondition(2, 'core.department_tree', null, null, [], 0)]),
            ],
            10,
        );
        $provider->compilePredicate($context, $operation, $policies);
        self::assertSame(1, $deptCalls);

        $largePolicies = new EffectivePolicySet(
            [
                new EffectiveConditionGroup(1, 100, 1, [
                    new EffectiveCondition(1, 'core.specified_departments', 99, 'core.department', [], 600),
                    new EffectiveCondition(2, 'core.self', null, null, [], 0),
                ]),
                new EffectiveConditionGroup(2, 100, 2, [
                    new EffectiveCondition(3, 'core.specified_departments', 99, 'core.department', [], 600),
                ]),
            ],
            10,
        );
        $targets = new TypedResourceTargetCollection([
            new TypedResourceTargetSet('core.department', ['10', '20']),
        ]);
        $decision = $provider->assertTargetsAllowed($context, $operation, $targets, $largePolicies);
        self::assertTrue($decision->allowed);
        self::assertSame(1, $targetSetCalls);
    }
}
