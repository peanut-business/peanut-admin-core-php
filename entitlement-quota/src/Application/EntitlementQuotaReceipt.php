<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Application;

use PeanutAdmin\EntitlementQuota\Package;

final readonly class EntitlementQuotaReceipt
{
    public function __construct(
        public string $operation,
        public string $meterKey,
        public string $targetType,
        public string $targetKey,
        public string $unitKey,
        public string $reservationKey,
        public int $amount,
        public string $state,
        public int $committedAmount,
        public int $reservedAmount,
        public int $limitAmount,
        public int $remainingAmount,
        public string $windowStart,
        public string $windowEnd,
        public string $reservedAt,
        public string $expiresAt,
        public ?string $settledAt,
        public string $policySnapshotSha256,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'meter_key' => $this->meterKey,
            'target_type' => $this->targetType,
            'target_key' => $this->targetKey,
            'unit_key' => $this->unitKey,
            'reservation_key' => $this->reservationKey,
            'amount' => $this->amount,
            'state' => $this->state,
            'committed_amount' => $this->committedAmount,
            'reserved_amount' => $this->reservedAmount,
            'limit_amount' => $this->limitAmount,
            'remaining_amount' => $this->remainingAmount,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'reserved_at' => $this->reservedAt,
            'expires_at' => $this->expiresAt,
            'settled_at' => $this->settledAt,
            'policy_snapshot_sha256' => $this->policySnapshotSha256,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value, string $expectedOperation): self
    {
        $expectedKeys = [
            'operation',
            'meter_key',
            'target_type',
            'target_key',
            'unit_key',
            'reservation_key',
            'amount',
            'state',
            'committed_amount',
            'reserved_amount',
            'limit_amount',
            'remaining_amount',
            'window_start',
            'window_end',
            'reserved_at',
            'expires_at',
            'settled_at',
            'policy_snapshot_sha256',
        ];
        $actualKeys = array_keys($value);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        $operations = [Package::RESERVE_OPERATION, Package::COMMIT_OPERATION, Package::RELEASE_OPERATION];
        if ($actualKeys !== $expectedKeys
            || !in_array($expectedOperation, $operations, true)
            || !is_string($value['operation'])
            || !hash_equals($expectedOperation, $value['operation'])
            || !is_string($value['meter_key'])
            || !is_string($value['target_type'])
            || !is_string($value['target_key'])
            || !is_string($value['unit_key'])
            || !is_string($value['reservation_key'])
            || preg_match('/^reservation_[0-9a-f]{32}$/D', $value['reservation_key']) !== 1
            || !is_int($value['amount'])
            || $value['amount'] < 1
            || !is_string($value['state'])
            || !in_array($value['state'], ['pending', 'committed', 'released'], true)
            || !is_int($value['committed_amount'])
            || $value['committed_amount'] < 0
            || !is_int($value['reserved_amount'])
            || $value['reserved_amount'] < 0
            || !is_int($value['limit_amount'])
            || $value['limit_amount'] < 1
            || !is_int($value['remaining_amount'])
            || $value['remaining_amount'] < 0
            || !is_string($value['window_start'])
            || !is_string($value['window_end'])
            || !is_string($value['reserved_at'])
            || !is_string($value['expires_at'])
            || ($value['settled_at'] !== null && !is_string($value['settled_at']))
            || !is_string($value['policy_snapshot_sha256'])
            || preg_match('/^[0-9a-f]{64}$/D', $value['policy_snapshot_sha256']) !== 1
        ) {
            throw EntitlementQuotaException::internal();
        }
        $expectedState = match ($expectedOperation) {
            Package::RESERVE_OPERATION => 'pending',
            Package::COMMIT_OPERATION => 'committed',
            Package::RELEASE_OPERATION => 'released',
        };
        if ($value['state'] !== $expectedState
            || ($expectedState === 'pending') !== ($value['settled_at'] === null)
            || $value['committed_amount'] > $value['limit_amount']
            || $value['reserved_amount'] > $value['limit_amount']
            || $value['committed_amount'] > PHP_INT_MAX - $value['reserved_amount']
            || $value['committed_amount'] + $value['reserved_amount'] > $value['limit_amount']
            || $value['remaining_amount']
                !== $value['limit_amount'] - $value['committed_amount'] - $value['reserved_amount']) {
            throw EntitlementQuotaException::internal();
        }

        return new self(
            $value['operation'],
            $value['meter_key'],
            $value['target_type'],
            $value['target_key'],
            $value['unit_key'],
            $value['reservation_key'],
            $value['amount'],
            $value['state'],
            $value['committed_amount'],
            $value['reserved_amount'],
            $value['limit_amount'],
            $value['remaining_amount'],
            $value['window_start'],
            $value['window_end'],
            $value['reserved_at'],
            $value['expires_at'],
            $value['settled_at'],
            $value['policy_snapshot_sha256'],
        );
    }
}
