<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Decision;

final readonly class AuthorizationDecision
{
    /** @param list<int> $policyIds */
    private function __construct(
        public bool $allowed,
        public string $reasonCode,
        public array $policyIds,
    ) {}

    /** @param list<int> $policyIds */
    public static function allow(array $policyIds = []): self
    {
        return new self(true, 'AUTHZ_ALLOWED', $policyIds);
    }

    public static function deny(string $reasonCode = 'AUTHZ_DATA_DENIED'): self
    {
        return new self(false, $reasonCode, []);
    }
}
