<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class ArtifactRecord extends TenantModel
{
    /** @var string */ protected $name = 'artifact';
}
