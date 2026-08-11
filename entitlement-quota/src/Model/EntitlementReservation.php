<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Model;

final readonly class EntitlementReservation
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $usageWindowId,
        public string $reservationKey,
        public string $meterKey,
        public string $targetType,
        public string $targetKey,
        public int $amount,
        public string $state,
        public int $revision,
        public int $createdByMemberId,
        public ?int $settledByMemberId,
        public string $reservedAt,
        public string $expiresAt,
        public ?string $settledAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['usage_window_id'],
            (string) $row['reservation_key'],
            (string) $row['meter_key'],
            (string) $row['target_type'],
            (string) $row['target_key'],
            (int) $row['amount'],
            (string) $row['state'],
            (int) $row['revision'],
            (int) $row['created_by_member_id'],
            $row['settled_by_member_id'] === null ? null : (int) $row['settled_by_member_id'],
            (string) $row['reserved_at'],
            (string) $row['expires_at'],
            $row['settled_at'] === null ? null : (string) $row['settled_at'],
        );
    }

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }

    public function isTerminal(): bool
    {
        return !$this->isPending();
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'usage_window_id' => $this->usageWindowId,
            'reservation_key' => $this->reservationKey,
            'meter_key' => $this->meterKey,
            'target_type' => $this->targetType,
            'target_key' => $this->targetKey,
            'amount' => $this->amount,
            'state' => $this->state,
            'revision' => $this->revision,
            'created_by_member_id' => $this->createdByMemberId,
            'settled_by_member_id' => $this->settledByMemberId,
            'reserved_at' => $this->reservedAt,
            'expires_at' => $this->expiresAt,
            'settled_at' => $this->settledAt,
        ];
    }
}
