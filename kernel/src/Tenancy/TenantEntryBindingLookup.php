<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

/** Resolve only a unique, currently active host/client binding to an active tenant. */
interface TenantEntryBindingLookup
{
    /** @return array{tenant_id:int,tenant_code:string}|null */
    public function binding(string $host, string $clientKey): ?array;
}
