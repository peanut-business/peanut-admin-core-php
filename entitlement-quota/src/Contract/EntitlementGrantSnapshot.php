<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Contract;

use DateTimeImmutable;
use DateTimeZone;

final readonly class EntitlementGrantSnapshot
{
    public DateTimeImmutable $effectiveFrom;
    public DateTimeImmutable $effectiveUntil;

    public function __construct(
        public string $grantKey,
        public string $policyRevisionKey,
        public string $meterKey,
        public string $unitKey,
        public int $limitAmount,
        public string $periodKind,
        DateTimeImmutable $effectiveFrom,
        DateTimeImmutable $effectiveUntil,
        public int $reservationTtlSeconds,
    ) {
        $utc = new DateTimeZone('UTC');
        $this->effectiveFrom = $effectiveFrom->setTimezone($utc);
        $this->effectiveUntil = $effectiveUntil->setTimezone($utc);
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'grant_key' => $this->grantKey,
            'policy_revision_key' => $this->policyRevisionKey,
            'meter_key' => $this->meterKey,
            'unit_key' => $this->unitKey,
            'limit_amount' => $this->limitAmount,
            'period_kind' => $this->periodKind,
            'effective_from' => $this->effectiveFrom->format('Y-m-d\TH:i:s.v\Z'),
            'effective_until' => $this->effectiveUntil->format('Y-m-d\TH:i:s.v\Z'),
            'reservation_ttl_seconds' => $this->reservationTtlSeconds,
        ];
    }
}
