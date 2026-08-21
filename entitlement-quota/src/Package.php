<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota;

final class Package
{
    public const MODULE_KEY = 'peanut.entitlement-quota';
    public const VERSION = '0.1.0-alpha.6';

    public const CHECK_OPERATION = 'entitlement-quota.check';
    public const RESERVE_OPERATION = 'entitlement-quota.reserve';
    public const COMMIT_OPERATION = 'entitlement-quota.commit';
    public const RELEASE_OPERATION = 'entitlement-quota.release';
    public const USAGE_OPERATION = 'entitlement-quota.usage';

    private function __construct() {}
}
