<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class AuthorizationAcceptanceEnvironment
{
    /**
     * @param array<string, int> $recordIds
     * @param array<string, int> $policyIds
     */
    public function __construct(
        public DataPermissionEngine $engine,
        public TenantContext $alphaContext,
        public TenantContext $betaContext,
        public ResourceProviderContractHarness $harness,
        public AuthorizationSqlTrace $trace,
        public int $accountId,
        public int $alphaTenantId,
        public int $betaTenantId,
        public int $alphaMemberId,
        public int $betaMemberId,
        public array $recordIds,
        public array $policyIds,
    ) {}
}
