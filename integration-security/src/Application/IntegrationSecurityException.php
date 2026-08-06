<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

use DomainException;

final class IntegrationSecurityException extends DomainException
{
    public function __construct(
        public readonly string $problemCode,
        string $message = 'The integration security request could not be completed.',
    ) {
        parent::__construct($message);
    }

    public static function denied(): self
    {
        return new self('INTEGRATION_PERMISSION_DENIED', 'Request is not authorized.');
    }
    public static function invalid(): self
    {
        return new self('INTEGRATION_INPUT_INVALID', 'Input is invalid.');
    }
    public static function machineNotFound(): self
    {
        return new self('MACHINE_IDENTITY_NOT_FOUND', 'Machine identity was not found.');
    }
    public static function tokenInvalid(): self
    {
        return new self('MACHINE_TOKEN_INVALID', 'Machine token is invalid.');
    }
    public static function tokenExpired(): self
    {
        return new self('MACHINE_TOKEN_EXPIRED', 'Machine token is expired.');
    }
    public static function scopeDenied(): self
    {
        return new self('MACHINE_SCOPE_DENIED', 'Machine scope is denied.');
    }
    public static function conflict(): self
    {
        return new self('INTEGRATION_REVISION_CONFLICT', 'The resource revision changed.');
    }
    public static function endpointNotFound(): self
    {
        return new self('WEBHOOK_ENDPOINT_NOT_FOUND', 'Webhook endpoint was not found.');
    }
    public static function destinationDenied(): self
    {
        return new self('WEBHOOK_DESTINATION_DENIED', 'Webhook destination is not allowed.');
    }
    public static function secretInvalid(): self
    {
        return new self('WEBHOOK_SECRET_INVALID', 'Webhook secret could not be opened.');
    }
    public static function sessionNotFound(): self
    {
        return new self('SESSION_DEVICE_NOT_FOUND', 'Session device was not found.');
    }
}
