<?php
declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class EntitlementUsageWindowRecord extends TenantModel
{
    /** @var string */ protected $name = 'entitlement_usage_window';
    /** @var string */ protected $dateFormat = 'Y-m-d H:i:s.v';
}
