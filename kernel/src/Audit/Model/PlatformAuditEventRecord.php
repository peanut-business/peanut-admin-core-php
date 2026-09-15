<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit\Model;

use think\Model;

final class PlatformAuditEventRecord extends Model
{
    /** @var string */ protected $name = 'platform_audit_event';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var list<string> */ protected $json = ['before_json', 'after_json', 'metadata_json'];
    /** @var bool */ protected $jsonAssoc = true;
}
