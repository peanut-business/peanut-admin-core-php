<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

final readonly class ProvisionedMachineIdentity
{
    public function __construct(public MachineIdentity $identity, public string $token) {}
}
