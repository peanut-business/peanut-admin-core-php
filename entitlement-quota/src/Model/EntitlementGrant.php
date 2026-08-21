<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Model;

final readonly class EntitlementGrant
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $grantKey,
        public string $state,
        public ?int $currentPolicyRevisionId,
        public int $revision,
        public int $createdByMemberId,
        public int $updatedByMemberId,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['grant_key'],
            (string) $row['state'],
            $row['current_policy_revision_id'] === null
                ? null
                : (int) $row['current_policy_revision_id'],
            (int) $row['revision'],
            (int) $row['created_by_member_id'],
            (int) $row['updated_by_member_id'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    public function isActive(): bool
    {
        return $this->state === 'active';
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'grant_key' => $this->grantKey,
            'state' => $this->state,
            'current_policy_revision_id' => $this->currentPolicyRevisionId,
            'revision' => $this->revision,
            'created_by_member_id' => $this->createdByMemberId,
            'updated_by_member_id' => $this->updatedByMemberId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
