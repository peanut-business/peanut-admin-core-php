<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Status;

use InvalidArgumentException;
use PeanutAdmin\OpsConsole\Support\Contract;

final readonly class OpsStatusSnapshot
{
    /**
     * @param list<array{key: string, status: string, critical: bool, latency_ms: int|float}> $checks
     */
    public function __construct(
        public string $health,
        public array $checks,
        public string $commit,
        public string $tree,
        public ?string $releaseKey,
        public string $builtAt,
        public int $appliedMigrations,
        public int $targetMigrations,
        public int $pendingMigrations,
        public string $migrationDigest,
        public bool $migrationDrift,
        public string $upgradeState,
        public string $upgradeCode,
        public ?string $sourceCommit,
        public ?string $targetCommit,
        public bool $repositoryClean,
        public bool $backupVerified,
        public bool $sourceEvidenceMatches,
    ) {
        if (!in_array($health, ['healthy', 'degraded', 'unhealthy'], true)
            || !in_array($upgradeState, ['configuration_required', 'blocked', 'ready', 'running', 'succeeded', 'failed'], true)
            || count($checks) > 32
            || min($appliedMigrations, $targetMigrations, $pendingMigrations) < 0
            || $appliedMigrations + $pendingMigrations !== $targetMigrations
        ) {
            throw new InvalidArgumentException('Invalid operations status.');
        }
        Contract::commit($commit);
        Contract::commit($tree);
        if ($releaseKey !== null) {
            Contract::qualifiedKey($releaseKey);
        }
        Contract::instant($builtAt);
        Contract::hash($migrationDigest);
        Contract::stableCode($upgradeCode);
        Contract::commit($sourceCommit);
        Contract::commit($targetCommit);
        $criticalCheckDown = false;
        foreach ($checks as $check) {
            $checkKeys = array_keys($check);
            sort($checkKeys);
            if ($checkKeys !== ['critical', 'key', 'latency_ms', 'status']
                || !in_array($check['status'], ['up', 'down'], true)
                || !is_bool($check['critical'])
                || (!is_int($check['latency_ms']) && !is_float($check['latency_ms']))
                || !is_finite((float) $check['latency_ms']) || $check['latency_ms'] < 0 || $check['latency_ms'] > 60000
            ) {
                throw new InvalidArgumentException('Invalid health check.');
            }
            Contract::qualifiedKey($check['key'], 64);
            $criticalCheckDown = $criticalCheckDown || ($check['critical'] && $check['status'] === 'down');
        }
        if (($health === 'healthy' && ($criticalCheckDown || $migrationDrift || $pendingMigrations > 0))
            || ($upgradeState === 'succeeded' && (!$repositoryClean || !$backupVerified || !$sourceEvidenceMatches))
        ) {
            throw new InvalidArgumentException('Inconsistent operations status.');
        }
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'health' => ['status' => $this->health, 'checks' => $this->checks],
            'version' => [
                'commit' => $this->commit, 'tree' => $this->tree,
                'release_key' => $this->releaseKey, 'built_at' => $this->builtAt,
            ],
            'migrations' => [
                'applied' => $this->appliedMigrations, 'target' => $this->targetMigrations,
                'pending' => $this->pendingMigrations, 'inventory_digest' => $this->migrationDigest,
                'drift' => $this->migrationDrift,
            ],
            'upgrade' => [
                'state' => $this->upgradeState, 'code' => $this->upgradeCode,
                'source_commit' => $this->sourceCommit, 'target_commit' => $this->targetCommit,
                'repository_clean' => $this->repositoryClean, 'backup_verified' => $this->backupVerified,
                'source_evidence_matches' => $this->sourceEvidenceMatches,
            ],
        ];
    }
}
