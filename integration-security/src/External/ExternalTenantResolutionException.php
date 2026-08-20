<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\External;

class ExternalTenantResolutionException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('EXTERNAL_CALLBACK_REJECTED');
    }
}
