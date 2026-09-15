<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class TenantAuditEventRecord extends TenantModel
{
    /** @var string */ protected $name = 'tenant_audit_event';
    /** @var list<string> */ protected $json = ['before_json', 'after_json', 'metadata_json'];
    /** @var bool */ protected $jsonAssoc = true;
}
