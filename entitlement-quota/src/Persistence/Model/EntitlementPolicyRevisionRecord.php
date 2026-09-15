<?php
declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class EntitlementPolicyRevisionRecord extends TenantModel
{
    /** @var string */ protected $name = 'entitlement_policy_revision';
    /** @var string */ protected $dateFormat = 'Y-m-d H:i:s.v';
}
