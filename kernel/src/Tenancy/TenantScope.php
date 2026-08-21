<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

/**
 * Immutable scope produced only after an upstream trusted context boundary succeeds.
 * This value object deliberately has no request or payload parser.
 */
final readonly class TenantScope
{
    private const MAX_CONTEXT_IDENTITY_BYTES = 256;

    private function __construct(
        private int $tenantId,
        private string $contextIdentity,
    ) {}

    public static function fromTrustedContext(int $tenantId, string $contextIdentity): self
    {
        $contextIdentity = trim($contextIdentity);
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Trusted TenantContext must contain a positive tenant ID');
        }
        if ($contextIdentity === ''
            || strlen($contextIdentity) > self::MAX_CONTEXT_IDENTITY_BYTES
            || preg_match('/[\x00-\x1F\x7F]/', $contextIdentity) === 1) {
            throw new \InvalidArgumentException('Trusted TenantContext identity is invalid');
        }

        return new self($tenantId, $contextIdentity);
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function contextIdentity(): string
    {
        return $this->contextIdentity;
    }
}
