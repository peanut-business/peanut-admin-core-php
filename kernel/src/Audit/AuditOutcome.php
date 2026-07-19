<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit;

enum AuditOutcome: string
{
    case Success = 'success';
    case Denied = 'denied';
    case Error = 'error';
}
