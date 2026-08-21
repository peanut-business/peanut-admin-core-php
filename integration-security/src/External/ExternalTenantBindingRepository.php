<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\External;

interface ExternalTenantBindingRepository
{
    /** @return list<ExternalTenantBinding> */
    public function byCallbackKey(string $provider, string $callbackKey): array;

    /** @return list<ExternalTenantBinding> */
    public function byClientIdentity(string $provider, string $identityHash): array;

    /** @return list<ExternalTenantBinding> */
    public function byProvider(string $provider): array;

    /** @return list<ExternalTenantBinding> */
    public function byTenant(string $provider, int $tenantId): array;

    /** @return list<ExternalTenantBinding> */
    public function byOAuthState(string $provider, string $stateHash): array;

    /** @return list<ExternalTenantBinding> */
    public function byOAuthTicket(string $ticketHash): array;
}
