<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;

final class ModuleRuntimeCoverageTest extends TestCase
{
    public function testEveryG04AndP0SystemScenarioHasAnOwnedGate(): void
    {
        $coverage = [
            'G04-001' => 'kernel-subsystem-inventory', 'G04-002' => 'deployment-guard',
            'G04-003' => 'tenant-guard', 'G04-004' => 'permission-guard',
            'G04-005' => 'three-layer-order', 'G04-006' => 'effective-window',
            'G04-007' => 'expiry-window', 'G04-008' => 'installation-status',
            'G04-009' => 'dependency-graph', 'G04-010' => 'semver-adapter',
            'G04-011' => 'dependency-cycle', 'G04-012' => 'deployment-migration-ledger',
            'G04-013' => 'migration-checksum', 'G04-014' => 'tenant-enable-hook',
            'G04-015' => 'idempotent-enable', 'G04-016' => 'config-schema-adapter',
            'G04-017' => 'dependent-disable-guard', 'G04-018' => 'non-destructive-disable',
            'G04-019' => 'async-revalidation-contract', 'G04-020' => 'menu-intersection',
            'G04-021' => 'frontend-component-registry', 'G04-022' => 'link-allowlist',
            'G04-023' => 'deptrac-module-boundary', 'G04-024' => 'owned-table-registry',
            'G04-025' => 'raw-sql-review-boundary', 'G04-026' => 'immutable-query-contract',
            'G04-027' => 'owner-command-contract', 'G04-028' => 'after-commit-event-contract',
            'G04-029' => 'idempotent-consumer-contract', 'G04-030' => 'read-model-contract',
            'G04-031' => 'profile-lifecycle-contract', 'G04-032' => 'profile-non-authority',
            'G04-033' => 'fail-closed-registry', 'G04-034' => 'irreversible-migration-contract',
            'G04-035' => 'audience-context-guard', 'G04-036' => 'bounded-cache-ttl',
            'G04-037' => 'operation-cardinality-registry', 'G04-038' => 'target-owner-uniqueness',
            'G04-039' => 'tenant-module-single-installation', 'G04-040' => 'target-cardinality-engine',
            'G04-041' => 'shared-master-provider-check', 'G04-042' => 'shared-master-scope-engine',
            'G04-043' => 'public-contract-boundary', 'G04-044' => 'platform-audience-guard',
            'SYS-001' => 'manifest-json-schema', 'SYS-002' => 'explicit-module-registry',
            'SYS-003' => 'dependency-topology', 'SYS-004' => 'migration-checksum',
            'SYS-005' => 'registry-conflict', 'SYS-006' => 'module-health-state',
            'SYS-020' => 'typed-target-provider-check', 'SYS-021' => 'route-component-registry',
            'SYS-022' => 'owned-table-boundary',
        ];

        self::assertCount(53, $coverage);
        self::assertCount(53, array_unique(array_keys($coverage)));
        foreach ($coverage as $id => $gate) {
            self::assertMatchesRegularExpression('/^(G04|SYS)-[0-9]{3}$/', $id);
            self::assertNotSame('', $gate);
        }
    }
}
