<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Tests\Integration\Application;

use DateTimeImmutable;
use PeanutAdmin\ReferenceCodes\Tests\Integration\Support\ReferenceCodesDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/ReferenceCodesDatabaseTestCase.php';

final class ReferenceCodeQueryTest extends ReferenceCodesDatabaseTestCase
{
    public function testOmittedAsOfCapturesOneUtcMillisecondInstant(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-now');
        $this->create($this->adminService($repository), $definition, $tenant['context']);
        $result = $this->query($repository)->list($definition, $tenant['context']);
        self::assertMatchesRegularExpression('/Z$/D', $result['as_of']);
        self::assertSame($result['as_of'], $result['items'][0]->asOf);
    }

    public function testEffectiveStartBoundaryIsInclusive(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-start');
        $this->create($this->adminService($repository), $definition, $tenant['context'], override: [
            'effective_at' => new DateTimeImmutable('2026-07-20T10:00:00.000Z'),
        ]);
        $entry = $this->query($repository)->get(
            $definition, $tenant['context'], 'sample-code', new DateTimeImmutable('2026-07-20T10:00:00.000Z'),
        );
        self::assertNotNull($entry->effective);
        self::assertSame(1, $entry->effective['revision']);
    }

    public function testExpiresBoundaryIsExclusive(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-expires');
        $this->create($this->adminService($repository), $definition, $tenant['context'], override: [
            'effective_at' => new DateTimeImmutable('2026-07-20T10:00:00.000Z'),
            'expires_at' => new DateTimeImmutable('2026-07-20T11:00:00.000Z'),
        ]);
        self::assertNotNull($this->query($repository)->get(
            $definition, $tenant['context'], 'sample-code', new DateTimeImmutable('2026-07-20T10:59:59.999Z'),
        )->effective);
        self::assertNull($this->query($repository)->get(
            $definition, $tenant['context'], 'sample-code', new DateTimeImmutable('2026-07-20T11:00:00.000Z'),
        )->effective);
    }

    public function testOverlappingIntervalsUseGreatestRevision(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-overlap');
        $service = $this->adminService($repository);
        $created = $this->create($service, $definition, $tenant['context'], override: [
            'label' => 'Older', 'effective_at' => new DateTimeImmutable('2026-07-20T00:00:00.000Z'),
        ]);
        $service->replace(
            $definition, $tenant['context'], 'sample-code', 'Newer', [], 'active', 0,
            new DateTimeImmutable('2026-07-20T00:00:00.000Z'), null, $created->etag,
        );
        $entry = $this->query($repository)->get(
            $definition, $tenant['context'], 'sample-code', new DateTimeImmutable('2026-07-20T02:00:00.000Z'),
        );
        self::assertNotNull($entry->effective);
        self::assertSame(2, $entry->effective['revision']);
        self::assertSame('Newer', $entry->effective['label']);
    }

    public function testInactiveWinnerIsVisibleOnlyForRequestedStatusOrAll(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-inactive');
        $this->create($this->adminService($repository), $definition, $tenant['context'], override: ['status' => 'inactive']);
        $query = $this->query($repository);
        self::assertCount(1, $query->list($definition, $tenant['context'], effectiveStatus: 'all')['items']);
        self::assertCount(1, $query->list($definition, $tenant['context'], effectiveStatus: 'inactive')['items']);
        self::assertCount(0, $query->list($definition, $tenant['context'], effectiveStatus: 'active')['items']);
    }

    public function testNoEffectiveVersionAppearsLastForAllFilter(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-null-last');
        $service = $this->adminService($repository);
        $this->create($service, $definition, $tenant['context'], 'future-code', [
            'effective_at' => new DateTimeImmutable('2099-01-01T00:00:00.000Z'),
        ]);
        $this->create($service, $definition, $tenant['context'], 'current-code');
        $items = $this->query($repository)->list($definition, $tenant['context'])['items'];
        self::assertSame(['current-code', 'future-code'], array_map(static fn($entry): string => $entry->code, $items));
        self::assertNull($items[1]->effective);
    }

    public function testOrderingUsesSortOrderThenAsciiBinaryCode(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-order');
        $service = $this->adminService($repository);
        foreach ([['z-code', 0], ['a-code', 0], ['b-code', -1]] as [$code, $sort]) {
            $this->create($service, $definition, $tenant['context'], $code, ['sort_order' => $sort]);
        }
        $items = $this->query($repository)->list($definition, $tenant['context'])['items'];
        self::assertSame(['b-code', 'a-code', 'z-code'], array_map(static fn($entry): string => $entry->code, $items));
    }

    public function testPaginationReportsFilteredTotalBeforeSlicing(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-page');
        $service = $this->adminService($repository);
        foreach (['a-code', 'b-code', 'c-code'] as $code) {
            $this->create($service, $definition, $tenant['context'], $code);
        }
        $result = $this->query($repository)->list($definition, $tenant['context'], page: 2, pageSize: 2);
        self::assertSame(3, $result['total']);
        self::assertSame(2, $result['page']);
        self::assertSame(['c-code'], array_map(static fn($entry): string => $entry->code, $result['items']));
    }

    public function testRetiredEntryIsHiddenAtAndAfterRetirementByDefault(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-retired');
        $service = $this->adminService($repository);
        $created = $this->create($service, $definition, $tenant['context']);
        $retired = $service->retire($definition, $tenant['context'], 'sample-code', $created->etag);
        self::assertNotNull($retired->retiredAt);
        $asOf = new DateTimeImmutable($retired->retiredAt);
        self::assertCount(0, $this->query($repository)->list($definition, $tenant['context'], asOf: $asOf)['items']);
        self::assertCount(1, $this->query($repository)->list(
            $definition, $tenant['context'], asOf: $asOf, includeRetired: true,
        )['items']);
    }

    public function testHistoricalReadBeforeRetirementResolvesOlderVersion(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-history');
        $service = $this->adminService($repository);
        $created = $this->create($service, $definition, $tenant['context'], override: [
            'effective_at' => new DateTimeImmutable('2020-01-01T00:00:00.000Z'),
        ]);
        usleep(2000);
        $service->retire($definition, $tenant['context'], 'sample-code', $created->etag);
        $historical = $this->query($repository)->get(
            $definition, $tenant['context'], 'sample-code', new DateTimeImmutable($created->createdAt),
        );
        self::assertSame('active', $historical->lifecycle);
        self::assertNotNull($historical->effective);
        self::assertSame(1, $historical->effective['revision']);
    }

    public function testDetailReturnsCurrentIdentityRevisionAndStrongEtag(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-detail');
        $created = $this->create($this->adminService($repository), $definition, $tenant['context']);
        $entry = $this->query($repository)->get($definition, $tenant['context'], 'sample-code');
        self::assertSame($created->revision, $entry->revision);
        self::assertSame($created->etag, $entry->etag);
        self::assertSame(['module_key', 'set_key', 'code', 'lifecycle', 'revision', 'etag', 'effective', 'created_at', 'updated_at', 'retired_at'], array_keys($entry->toArray()));
    }

    public function testResolveReturnsOnlyActiveSelectableEntry(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-resolve');
        $service = $this->adminService($repository);
        $this->create($service, $definition, $tenant['context'], 'active-code');
        $this->create($service, $definition, $tenant['context'], 'inactive-code', ['status' => 'inactive']);
        $query = $this->query($repository);
        $resolved = $query->resolve($definition, $tenant['context'], 'active-code');
        self::assertNotNull($resolved);
        self::assertSame('active-code', $resolved->code);
        self::assertNull($query->resolve($definition, $tenant['context'], 'inactive-code'));
    }

    public function testListActiveCandidatesExcludesInactiveFutureAndRetired(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-candidates');
        $service = $this->adminService($repository);
        $active = $this->create($service, $definition, $tenant['context'], 'active-code');
        $this->create($service, $definition, $tenant['context'], 'inactive-code', ['status' => 'inactive']);
        $this->create($service, $definition, $tenant['context'], 'future-code', ['effective_at' => new DateTimeImmutable('2099-01-01T00:00:00.000Z')]);
        $retired = $this->create($service, $definition, $tenant['context'], 'retired-code');
        $service->retire($definition, $tenant['context'], 'retired-code', $retired->etag);
        self::assertSame(['active-code'], array_map(
            static fn($entry): string => $entry->code,
            $this->query($repository)->listActiveCandidates($definition, $tenant['context']),
        ));
        self::assertSame(1, $active->revision);
    }

    public function testCorruptionFailsClosedInsteadOfFallingThrough(): void
    {
        [$definition, $repository, $tenant] = $this->fixture('query-corruption');
        $created = $this->create($this->adminService($repository), $definition, $tenant['context']);
        $this->database->exec('UPDATE pa_reference_code_entry SET revision = 2');
        $this->expectReferenceCodeError('INTERNAL_ERROR', 500, fn() => $this->query($repository)->get(
            $definition, $tenant['context'], 'sample-code',
        ));
        self::assertSame(1, $created->revision);
    }

    /** @return array{\PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetDefinition, \PeanutAdmin\ReferenceCodes\Persistence\PdoReferenceCodeRepository, array{tenant_id:int, member_id:int, context:\PeanutAdmin\Kernel\Auth\TenantContext}} */
    private function fixture(string $tenantCode): array
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);

        return [$definition, $repository, $this->tenant($tenantCode)];
    }
}
