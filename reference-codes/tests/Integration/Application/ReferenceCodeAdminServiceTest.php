<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Tests\Integration\Application;

use DateTimeImmutable;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetRegistry;
use PeanutAdmin\ReferenceCodes\Tests\Integration\Support\ReferenceCodesDatabaseTestCase;
use RuntimeException;

require_once dirname(__DIR__) . '/Support/ReferenceCodesDatabaseTestCase.php';

final class ReferenceCodeAdminServiceTest extends ReferenceCodesDatabaseTestCase
{
    public function testSynchronizesDefinitionsIdempotently(): void
    {
        $definition = $this->definition();
        $repository = new \PeanutAdmin\ReferenceCodes\Persistence\PdoReferenceCodeRepository($this->database);
        self::assertSame(
            ['inserted' => 1, 'updated' => 0, 'retired' => 0, 'reactivated' => 0],
            $repository->synchronize($this->registry($definition), new DateTimeImmutable(self::NOW)),
        );
        self::assertSame(
            ['inserted' => 0, 'updated' => 0, 'retired' => 0, 'reactivated' => 0],
            $repository->synchronize($this->registry($definition), new DateTimeImmutable(self::NOW)),
        );
    }

    public function testSynchronizeUpdatesRetiresAndReactivatesStableIdentity(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $id = (int) $this->scalar('SELECT id FROM pa_reference_code_set');
        $changed = $this->definition(name: 'Changed generic codes');
        self::assertSame(1, $repository->synchronize($this->registry($changed), new DateTimeImmutable(self::NOW))['updated']);
        self::assertSame(1, $repository->synchronize(new ReferenceCodeSetRegistry(), new DateTimeImmutable(self::NOW))['retired']);
        self::assertSame(1, $repository->synchronize($this->registry($definition), new DateTimeImmutable(self::NOW))['reactivated']);
        self::assertSame($id, (int) $this->scalar('SELECT id FROM pa_reference_code_set'));
        self::assertSame(4, (int) $this->scalar('SELECT revision FROM pa_reference_code_set'));
    }

    public function testCreateUsesContextAndCreatesRevisionOne(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('admin-create');
        $created = $this->create($this->adminService($repository), $definition, $tenant['context']);
        self::assertSame(1, $created->revision);
        self::assertSame('"rev-1"', $created->etag);
        self::assertSame('active', $created->lifecycle);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM pa_reference_code_entry'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM pa_reference_code_entry_version'));
    }

    public function testCreateWithFutureVersionReturnsNoCurrentEffectiveValue(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('admin-future');
        $created = $this->create($this->adminService($repository), $definition, $tenant['context'], override: [
            'effective_at' => new DateTimeImmutable('2099-01-01T00:00:00.000Z'),
        ]);
        self::assertNull($created->effective);
    }

    public function testCreateRequiresExactIfNoneMatchWildcard(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('admin-precondition');
        foreach ([null, '', '"rev-1"', '*, "rev-1"'] as $precondition) {
            $this->expectReferenceCodeError('PRECONDITION_REQUIRED', 428, fn() => $this->adminService($repository)->create(
                $definition,
                $tenant['context'],
                'sample-code',
                'Label',
                [],
                'active',
                0,
                new DateTimeImmutable('2026-07-20T00:00:00.000Z'),
                null,
                $precondition,
            ));
        }
    }

    public function testDuplicateActiveCodeReturnsAlreadyExists(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('admin-duplicate');
        $service = $this->adminService($repository);
        $this->create($service, $definition, $tenant['context']);
        $this->expectReferenceCodeError('REFERENCE_CODE_ALREADY_EXISTS', 412, fn() => $this->create(
            $service,
            $definition,
            $tenant['context'],
        ));
    }

    public function testRetiredCodeCannotBeRecreated(): void
    {
        [$definition, $service, $tenant, $created] = $this->createdFixture('admin-recreate');
        $service->retire($definition, $tenant['context'], 'sample-code', $created->etag);
        $this->expectReferenceCodeError('REFERENCE_CODE_RETIRED', 409, fn() => $this->create(
            $service,
            $definition,
            $tenant['context'],
        ));
    }

    public function testReplaceAppendsOneVersionAndKeepsCodeIdentity(): void
    {
        [$definition, $service, $tenant, $created] = $this->createdFixture('admin-replace');
        $replaced = $service->replace(
            $definition,
            $tenant['context'],
            'sample-code',
            'Changed label',
            ['flag' => true],
            'inactive',
            9,
            new DateTimeImmutable('2026-07-20T01:00:00.000Z'),
            null,
            $created->etag,
        );
        self::assertSame(2, $replaced->revision);
        self::assertSame('sample-code', $replaced->code);
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM pa_reference_code_entry_version'));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM pa_reference_code_entry WHERE code = 'sample-code'"));
    }

    public function testReplaceRejectsStaleOrMalformedStrongEtag(): void
    {
        [$definition, $service, $tenant] = $this->createdFixture('admin-stale');
        foreach ([null, '*', 'W/"rev-1"', '"rev-0"', '"rev-1", "rev-2"'] as $invalid) {
            $this->expectReferenceCodeError('PRECONDITION_REQUIRED', 428, fn() => $service->replace(
                $definition,
                $tenant['context'],
                'sample-code',
                'Changed',
                [],
                'active',
                0,
                new DateTimeImmutable('2026-07-20T01:00:00.000Z'),
                null,
                $invalid,
            ));
        }
        $this->expectReferenceCodeError('REFERENCE_CODE_REVISION_MISMATCH', 412, fn() => $service->replace(
            $definition,
            $tenant['context'],
            'sample-code',
            'Changed',
            [],
            'active',
            0,
            new DateTimeImmutable('2026-07-20T01:00:00.000Z'),
            null,
            '"rev-2"',
        ));
    }

    public function testRetireCreatesTerminalInactiveVersionCopyingLastValues(): void
    {
        [$definition, $service, $tenant, $created] = $this->createdFixture('admin-retire');
        $changed = $service->replace(
            $definition,
            $tenant['context'],
            'sample-code',
            'Last label',
            ['marker' => 'safe'],
            'active',
            -3,
            new DateTimeImmutable('2026-07-20T01:00:00.000Z'),
            null,
            $created->etag,
        );
        $retired = $service->retire($definition, $tenant['context'], 'sample-code', $changed->etag);
        self::assertSame('retired', $retired->lifecycle);
        self::assertSame(3, $retired->revision);
        self::assertNotNull($retired->effective);
        self::assertSame('inactive', $retired->effective['status']);
        self::assertSame('Last label', $retired->effective['label']);
        self::assertSame(['marker' => 'safe'], $retired->effective['metadata']);
        self::assertSame(-3, $retired->effective['sort_order']);
    }

    public function testRetiredIdentityRejectsReplaceAndSecondRetire(): void
    {
        [$definition, $service, $tenant, $created] = $this->createdFixture('admin-terminal');
        $retired = $service->retire($definition, $tenant['context'], 'sample-code', $created->etag);
        $this->expectReferenceCodeError('REFERENCE_CODE_RETIRED', 409, fn() => $service->replace(
            $definition,
            $tenant['context'],
            'sample-code',
            'Changed',
            [],
            'active',
            0,
            new DateTimeImmutable('2026-07-20T01:00:00.000Z'),
            null,
            $retired->etag,
        ));
        $this->expectReferenceCodeError('REFERENCE_CODE_RETIRED', 409, fn() => $service->retire(
            $definition,
            $tenant['context'],
            'sample-code',
            $retired->etag,
        ));
    }

    public function testRejectsInvalidCodeIdentity(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('admin-code');
        foreach (['Invalid', 'with_underscore', 'a-', str_repeat('a', 65), ''] as $code) {
            $this->expectReferenceCodeError('REFERENCE_CODE_REQUEST_INVALID', 422, fn() => $this->create(
                $this->adminService($repository),
                $definition,
                $tenant['context'],
                $code,
            ));
        }
    }

    public function testTrimsValidLabelAndRejectsInvalidUtf8OrLength(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('admin-label');
        $created = $this->create($this->adminService($repository), $definition, $tenant['context'], override: [
            'label' => '  Trimmed label  ',
        ]);
        self::assertNotNull($created->effective);
        self::assertSame('Trimmed label', $created->effective['label']);
        foreach (['   ', str_repeat('x', 161), "invalid\xFF"] as $label) {
            $this->expectReferenceCodeError('REFERENCE_CODE_REQUEST_INVALID', 422, fn() => $this->create(
                $this->adminService($repository),
                $definition,
                $tenant['context'],
                'other-' . strlen($label),
                ['label' => $label],
            ));
        }
    }

    public function testAcceptsOnlyBoundedScalarMetadataAndCanonicalizesKeys(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('admin-metadata-good');
        $created = $this->create($this->adminService($repository), $definition, $tenant['context'], override: [
            'metadata' => ['z-value' => null, 'a-value' => true, 'count' => 2, 'ratio' => 1.5, 'text' => 'neutral'],
        ]);
        self::assertNotNull($created->effective);
        self::assertSame(['a-value', 'count', 'ratio', 'text', 'z-value'], array_keys($created->effective['metadata']));
    }

    public function testRejectsMetadataContainersKeysAndKeyCount(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('admin-metadata-shape');
        foreach ([
            ['nested' => ['value' => true]],
            ['list' => [1, 2]],
            ['Invalid_Key' => true],
            array_fill_keys(array_map(static fn(int $i): string => 'key-' . $i, range(1, 33)), true),
        ] as $index => $metadata) {
            $this->expectReferenceCodeError('REFERENCE_CODE_METADATA_INVALID', 422, fn() => $this->create(
                $this->adminService($repository),
                $definition,
                $tenant['context'],
                'shape-' . $index,
                ['metadata' => $metadata],
            ));
        }
    }

    public function testRejectsMetadataUtf8StringSizeEncodedSizeAndNonFiniteNumbers(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('admin-metadata-bounds');
        foreach ([
            ['text' => "invalid\xFF"],
            ['text' => str_repeat('x', 501)],
            array_fill_keys(array_map(static fn(int $i): string => 'key-' . $i, range(1, 32)), str_repeat('x', 300)),
            ['number' => INF],
        ] as $index => $metadata) {
            $this->expectReferenceCodeError('REFERENCE_CODE_METADATA_INVALID', 422, fn() => $this->create(
                $this->adminService($repository),
                $definition,
                $tenant['context'],
                'bounds-' . $index,
                ['metadata' => $metadata],
            ));
        }
    }

    public function testRejectsInvalidStatusSortAndIntervalsWithoutTruncation(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('admin-interval');
        foreach ([
            ['status' => 'custom', 'expected' => 'REFERENCE_CODE_REQUEST_INVALID'],
            ['sort_order' => 1000001, 'expected' => 'REFERENCE_CODE_REQUEST_INVALID'],
            ['effective_at' => new DateTimeImmutable('2026-07-20T00:00:00.000001Z'), 'expected' => 'REFERENCE_CODE_INTERVAL_INVALID'],
            ['effective_at' => new DateTimeImmutable('2026-07-20T01:00:00.000Z'), 'expires_at' => new DateTimeImmutable('2026-07-20T01:00:00.000Z'), 'expected' => 'REFERENCE_CODE_INTERVAL_INVALID'],
        ] as $index => $override) {
            $expected = $override['expected'];
            unset($override['expected']);
            $this->expectReferenceCodeError($expected, 422, fn() => $this->create(
                $this->adminService($repository),
                $definition,
                $tenant['context'],
                'interval-' . $index,
                $override,
            ));
        }
    }

    public function testOuterTransactionRollbackRemovesIdentityAndVersion(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('admin-rollback');
        try {
            $repository->atomically(function () use ($repository, $definition, $tenant): void {
                $this->create($this->adminService($repository), $definition, $tenant['context']);
                throw new RuntimeException('Injected failure after mutation.');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('Injected failure after mutation.', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pa_reference_code_entry'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pa_reference_code_entry_version'));
    }

    /** @return array{\PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetDefinition, \PeanutAdmin\ReferenceCodes\Application\ReferenceCodeAdminService, array{tenant_id:int, member_id:int, context:\PeanutAdmin\Kernel\Auth\TenantContext}, \PeanutAdmin\ReferenceCodes\Application\EffectiveReferenceCode} */
    private function createdFixture(string $tenantCode): array
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant($tenantCode);
        $service = $this->adminService($repository);

        return [$definition, $service, $tenant, $this->create($service, $definition, $tenant['context'])];
    }
}
