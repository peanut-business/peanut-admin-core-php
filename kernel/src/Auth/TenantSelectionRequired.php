<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;

final readonly class TenantSelectionRequired
{
    /** @param non-empty-list<TenantChoice> $tenants */
    public function __construct(
        public RawToken $challenge,
        public DateTimeImmutable $expiresAt,
        public array $tenants,
    ) {}

    /** @return array<string, mixed> */
    public function responseData(): array
    {
        return [
            'state' => 'tenant_selection_required',
            'challenge_token' => $this->challenge->expose(),
            'expires_at' => $this->expiresAt->format('Y-m-d\TH:i:s.v\Z'),
            'tenants' => array_map(
                static fn(TenantChoice $choice): array => $choice->toArray(),
                $this->tenants,
            ),
        ];
    }
}
