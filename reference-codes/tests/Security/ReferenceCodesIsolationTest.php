<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Tests\Security;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetRegistry;
use PeanutAdmin\ReferenceCodes\Tests\Integration\Support\ReferenceCodesDatabaseTestCase;
use ReflectionClass;
use ReflectionNamedType;

require_once dirname(__DIR__) . '/Integration/Support/ReferenceCodesDatabaseTestCase.php';

final class ReferenceCodesIsolationTest extends ReferenceCodesDatabaseTestCase
{
    public function testListReturnsOnlyCurrentTenantRows(): void
    {
        [$definition, $repository, $alpha, $beta] = $this->twoTenants('security-list');
        $service = $this->adminService($repository);
        $this->create($service, $definition, $alpha['context'], override: ['label' => 'Alpha private']);
        $this->create($service, $definition, $beta['context'], override: ['label' => 'Beta private']);
        $alphaItems = $this->query($repository)->list($definition, $alpha['context'])['items'];
        self::assertCount(1, $alphaItems);
        self::assertNotNull($alphaItems[0]->effective);
        self::assertSame('Alpha private', $alphaItems[0]->effective['label']);
    }

    public function testDetailDoesNotRevealSameCodeInAnotherTenant(): void
    {
        [$definition, $repository, $alpha, $beta] = $this->twoTenants('security-detail');
        $this->create($this->adminService($repository), $definition, $beta['context']);
        $this->expectReferenceCodeError('REFERENCE_CODE_NOT_FOUND', 404, fn() => $this->query($repository)->get(
            $definition,
            $alpha['context'],
            'sample-code',
        ));
    }

    public function testMismatchedContextMemberCannotOwnWrites(): void
    {
        [$definition, $repository, $alpha, $beta] = $this->twoTenants('security-actor');
        $forged = $this->context($alpha['tenant_id'], $beta['member_id']);
        $this->expectReferenceCodeError('REFERENCE_CODE_NOT_FOUND', 404, fn() => $this->create(
            $this->adminService($repository),
            $definition,
            $forged,
        ));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pa_reference_code_entry'));
    }

    public function testCrossTenantReplaceIsNonEnumerating(): void
    {
        [$definition, $repository, $alpha, $beta] = $this->twoTenants('security-replace');
        $created = $this->create($this->adminService($repository), $definition, $beta['context']);
        $this->expectReferenceCodeError('REFERENCE_CODE_NOT_FOUND', 404, fn() => $this->adminService($repository)->replace(
            $definition,
            $alpha['context'],
            'sample-code',
            'Changed',
            [],
            'active',
            0,
            new DateTimeImmutable('2026-07-20T01:00:00.000Z'),
            null,
            $created->etag,
        ));
    }

    public function testCrossTenantRetireIsNonEnumerating(): void
    {
        [$definition, $repository, $alpha, $beta] = $this->twoTenants('security-retire');
        $created = $this->create($this->adminService($repository), $definition, $beta['context']);
        $this->expectReferenceCodeError('REFERENCE_CODE_NOT_FOUND', 404, fn() => $this->adminService($repository)->retire(
            $definition,
            $alpha['context'],
            'sample-code',
            $created->etag,
        ));
    }

    public function testRetiredOrDigestMismatchedSetIsNotFound(): void
    {
        [$definition, $repository, $alpha] = $this->twoTenants('security-set');
        $repository->synchronize(new ReferenceCodeSetRegistry(), new DateTimeImmutable(self::NOW));
        $this->expectReferenceCodeError('REFERENCE_CODE_SET_NOT_FOUND', 404, fn() => $this->query($repository)->list(
            $definition,
            $alpha['context'],
        ));
        $changed = $this->definition(name: 'Changed');
        $repository->synchronize($this->registry($changed), new DateTimeImmutable(self::NOW));
        $this->expectReferenceCodeError('REFERENCE_CODE_SET_NOT_FOUND', 404, fn() => $this->query($repository)->list(
            $definition,
            $alpha['context'],
        ));
    }

    public function testResponseNeverExposesNumericDatabaseOrActorIds(): void
    {
        [$definition, $repository, $alpha] = $this->twoTenants('security-response');
        $entry = $this->create($this->adminService($repository), $definition, $alpha['context']);
        $json = json_encode($entry->toArray(), JSON_THROW_ON_ERROR);
        foreach (['tenant_id', 'member_id', 'set_id', 'entry_id', 'created_by', 'updated_by', 'changed_by'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
    }

    public function testAuditMetadataContainsOnlyRedactedScalarEvidence(): void
    {
        [$definition, $repository, $alpha] = $this->twoTenants('security-audit');
        $entry = $this->create($this->adminService($repository), $definition, $alpha['context'], override: [
            'label' => 'Sensitive label', 'metadata' => ['private-value' => 'do-not-audit'],
            'effective_at' => new DateTimeImmutable('2099-01-01T00:00:00.000Z'),
        ]);
        self::assertNull($entry->effective);
        $metadata = $entry->auditMetadata(['metadata', 'label', 'status']);
        $json = json_encode($metadata, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Sensitive label', $json);
        self::assertStringNotContainsString('do-not-audit', $json);
        self::assertArrayNotHasKey('tenant_id', $metadata);
        self::assertArrayNotHasKey('member_id', $metadata);
        self::assertSame('active', $metadata['effective_status']);
        self::assertSame('2099-01-01T00:00:00.000Z', $metadata['effective_at']);
        self::assertIsString($metadata['changed_fields']);
        self::assertSame(['label', 'metadata', 'status'], explode(',', $metadata['changed_fields']));
    }

    public function testNotFoundAndInternalErrorsDoNotLeakSensitiveValues(): void
    {
        [$definition, $repository, $alpha] = $this->twoTenants('security-error');
        foreach ([
            fn() => $this->query($repository)->get($definition, $alpha['context'], 'secret-code'),
            fn() => $this->adminService($repository)->retire($definition, $alpha['context'], 'secret-code', '"rev-1"'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected non-enumerating not-found failure.');
            } catch (\PeanutAdmin\ReferenceCodes\Application\ReferenceCodeException $exception) {
                self::assertStringNotContainsString('secret-code', $exception->getMessage());
                self::assertStringNotContainsString('SELECT', $exception->getMessage());
            }
        }
    }

    public function testPublicTenantOperationsRequireTrustedTenantContext(): void
    {
        foreach ([
            \PeanutAdmin\ReferenceCodes\Application\ReferenceCodeAdminService::class => ['create', 'replace', 'retire'],
            \PeanutAdmin\ReferenceCodes\Application\ReferenceCodeQuery::class => ['get', 'list', 'resolve', 'listActiveCandidates'],
        ] as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $method) {
                $parameters = $reflection->getMethod($method)->getParameters();
                $context = array_values(array_filter(
                    $parameters,
                    static fn($parameter): bool => $parameter->getName() === 'context',
                ));
                self::assertCount(1, $context);
                $type = $context[0]->getType();
                self::assertInstanceOf(ReflectionNamedType::class, $type);
                self::assertSame(TenantContext::class, $type->getName());
                self::assertSame([], array_values(array_filter(
                    $parameters,
                    static fn($parameter): bool => $parameter->getName() === 'tenantId',
                )));
            }
        }
    }

    /** @return array{\PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetDefinition, \PeanutAdmin\ReferenceCodes\Persistence\PdoReferenceCodeRepository, array{tenant_id:int, member_id:int, context:TenantContext}, array{tenant_id:int, member_id:int, context:TenantContext}} */
    private function twoTenants(string $prefix): array
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);

        return [$definition, $repository, $this->tenant($prefix . '-alpha'), $this->tenant($prefix . '-beta')];
    }
}
