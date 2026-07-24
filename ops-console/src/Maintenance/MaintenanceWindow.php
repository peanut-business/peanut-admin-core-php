<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Maintenance;

use InvalidArgumentException;
use PeanutAdmin\OpsConsole\Support\Contract;

final readonly class MaintenanceWindow
{
    public function __construct(
        public string $maintenanceKey,
        public string $state,
        public string $reasonKey,
        public string $startsAt,
        public string $endsAt,
        public int $revision,
    ) {
        Contract::opaqueKey($maintenanceKey, 'maintenance_');
        Contract::qualifiedKey($reasonKey, 64);
        Contract::instant($startsAt);
        Contract::instant($endsAt);
        if (!in_array($state, ['scheduled', 'active', 'closed'], true) || $revision < 1) {
            throw new InvalidArgumentException('Invalid maintenance window.');
        }
    }

    /** @return array{maintenance_key: string, state: string, reason_key: string, starts_at: string, ends_at: string, revision: int} */
    public function toPublicArray(): array
    {
        return [
            'maintenance_key' => $this->maintenanceKey,
            'state' => $this->state,
            'reason_key' => $this->reasonKey,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'revision' => $this->revision,
        ];
    }
}
