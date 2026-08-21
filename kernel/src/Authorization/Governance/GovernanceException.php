<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Governance;

use RuntimeException;

final class GovernanceException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
