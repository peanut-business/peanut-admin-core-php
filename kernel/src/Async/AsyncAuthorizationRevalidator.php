<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Async;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface AsyncAuthorizationRevalidator
{
    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext;
}
