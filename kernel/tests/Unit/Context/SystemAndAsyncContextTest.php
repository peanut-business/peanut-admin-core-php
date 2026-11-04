<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Context;

use PeanutAdmin\Kernel\Async\AsyncAuthorizationRevalidator;
use PeanutAdmin\Kernel\Async\JobHandlerAdapter;
use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Tenancy\TenantNamespace;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Context\SystemActorDefinition;
use PeanutAdmin\Kernel\Context\SystemActorRegistry;
use PeanutAdmin\Kernel\Context\SystemContextFactory;
use PeanutAdmin\Kernel\Context\SystemTenantResolver;
use PeanutAdmin\Kernel\Context\TenantContextAccessor;
use PHPUnit\Framework\TestCase;

final class SystemAndAsyncContextTest extends TestCase
{
    public function testSystemActorRequiresManifestOperationAndExplicitTenant(): void
    {
        $resolver = new class implements SystemTenantResolver {
            public function activeTenantIdByCode(string $tenantCode): ?int
            {
                return $tenantCode === 'alpha' ? 101 : null;
            }
        };
        $factory = new SystemContextFactory(new SystemActorRegistry([
            new SystemActorDefinition('example.scheduler', 'tenant', ['example.reconcile']),
            new SystemActorDefinition('platform.maintenance', 'platform', ['platform.health']),
        ]), $resolver);

        $tenant = $factory->tenant(
            'example.scheduler',
            'example.reconcile',
            'alpha',
            'operation-1',
        );
        self::assertSame(101, $tenant->tenantId);
        self::assertSame('platform.maintenance', $factory->platform(
            'platform.maintenance',
            'platform.health',
            'operation-2',
        )->actorKey);

        $this->expectException(AuthException::class);
        $factory->tenant('example.scheduler', 'example.reconcile', '', 'operation-3');
    }

    public function testTenantResourceNamesSeparateTenantsKindsAndLogicalRevisions(): void
    {
        $tenantA = TenantScope::fromTrustedContext(101, 'request-a');
        $tenantB = TenantScope::fromTrustedContext(202, 'request-b');
        // Revision is part of the caller's logical key in the current namespace API.
        $logicalKey = 'example.record:revision=7:record-1';
        $alpha = TenantNamespace::cacheKey($tenantA, $logicalKey);
        $beta = TenantNamespace::cacheKey($tenantB, $logicalKey);
        $newRevision = TenantNamespace::cacheKey($tenantA, 'example.record:revision=8:record-1');

        self::assertNotSame($alpha, $beta);
        self::assertNotSame($alpha, $newRevision);
        self::assertNotSame($alpha, TenantNamespace::cacheTag($tenantA, $logicalKey));
        self::assertNotSame(
            TenantNamespace::lockName($tenantA, $logicalKey),
            TenantNamespace::lockName($tenantB, $logicalKey),
        );
    }

    public function testSignedEnvelopeIsRevalidatedAndCannotTrustTamperedTenantOrTargets(): void
    {
        $tenantContext = $this->tenantContext();
        $targets = [new RequestedTargetSet('example.project', ['project-b', 'project-a'], 'destination')];
        $authorized = AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenantContext,
            'example.work-item',
            'export',
            $targets,
            'basis-v1',
        ));
        $codec = new TrustedEnvelopeCodec('test-envelope-signing-key-at-least-32-bytes');
        $encoded = $codec->issue($authorized, 'operation-queue', 'trace-http-to-worker');
        self::assertSame('destination', $codec->verify($encoded)->requestedTargets[0]->targetRole);

        $allowed = true;
        $revalidator = new class ($tenantContext, $allowed) implements AsyncAuthorizationRevalidator {
            public function __construct(
                private readonly TenantContext $context,
                public bool $allowed,
            ) {}

            public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
            {
                if (!$this->allowed) {
                    throw new AuthException('AUTH_MEMBER_UNAVAILABLE', 403);
                }

                return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
                    $this->context,
                    $envelope->resourceKey,
                    $envelope->operation,
                    $envelope->requestedTargets,
                    'basis-revalidated',
                ));
            }
        };
        $adapter = new JobHandlerAdapter($codec, $revalidator);
        $result = $adapter->handle(
            $encoded,
            static fn(AuthorizedOperationContext $context, VerifiedJobEnvelope $envelope): string =>
                $context->authorizationBasisDigest . ':' . $envelope->traceId,
        );
        self::assertSame('basis-revalidated:trace-http-to-worker', $result);

        $revalidator->allowed = false;
        try {
            $adapter->handle($encoded, static fn(): null => null);
            self::fail('Revoked worker authorization must be rejected at execution time.');
        } catch (AuthException $exception) {
            self::assertSame('AUTH_MEMBER_UNAVAILABLE', $exception->errorCode);
        }

        $document = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $document['payload']['tenant_id'] = 202;
        $tampered = json_encode($document, JSON_THROW_ON_ERROR);
        $this->expectException(AuthException::class);
        $codec->verify($tampered);
    }

    public function testOperationContextsDoNotMutateSessionTargetState(): void
    {
        $tenantContext = $this->tenantContext();
        $projectA = AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenantContext,
            'example.work-item',
            'update',
            [new RequestedTargetSet('example.project', ['project-a'])],
            'basis-a',
        ));
        $projectB = AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenantContext,
            'example.work-item',
            'read',
            [new RequestedTargetSet('example.project', ['project-b'])],
            'basis-b',
        ));

        self::assertNotSame($projectA, $projectB);
        self::assertSame($tenantContext, $projectA->tenantContext);
        self::assertSame($tenantContext, $projectB->tenantContext);
        self::assertArrayNotHasKey('currentTarget', get_object_vars($tenantContext));
    }

    public function testRequestContextAccessorClearsBetweenLongRunningRequests(): void
    {
        $accessor = new TenantContextAccessor();
        $accessor->bind($this->tenantContext());
        self::assertSame(101, $accessor->require()->tenantId);
        $accessor->clear();

        $this->expectException(AuthException::class);
        $accessor->require();
    }

    private function tenantContext(): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            '01JZ0000000000000000000001',
            101,
            12,
            501,
            'admin-web',
            new \DateTimeImmutable('2026-07-16T03:00:00Z'),
            18,
            1,
        ), 'request-context');
    }
}
