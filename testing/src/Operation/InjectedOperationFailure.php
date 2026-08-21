<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Operation;

use RuntimeException;

final class InjectedOperationFailure extends RuntimeException
{
    public function __construct(public readonly string $checkpoint)
    {
        parent::__construct(sprintf('Injected operation failure at checkpoint "%s".', $checkpoint));
    }
}
