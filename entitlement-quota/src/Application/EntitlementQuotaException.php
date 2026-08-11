<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Application;

use RuntimeException;

final class EntitlementQuotaException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message = 'The entitlement quota request is invalid.'): self
    {
        return new self('ENTITLEMENT_QUOTA_INVALID', 422, $message);
    }

    public static function notFound(): self
    {
        return new self('ENTITLEMENT_QUOTA_NOT_FOUND', 404, 'The entitlement quota target is unavailable.');
    }

    public static function denied(): self
    {
        return new self('ENTITLEMENT_QUOTA_DENIED', 403, 'The entitlement quota policy denies this request.');
    }

    public static function exceeded(): self
    {
        return new self('ENTITLEMENT_QUOTA_EXCEEDED', 409, 'The entitlement quota capacity is exceeded.');
    }

    public static function conflict(): self
    {
        return new self('ENTITLEMENT_QUOTA_CONFLICT', 409, 'The entitlement quota reservation has changed.');
    }

    public static function providerUnavailable(): self
    {
        return new self(
            'ENTITLEMENT_QUOTA_PROVIDER_UNAVAILABLE',
            503,
            'The entitlement quota policy provider is unavailable.',
        );
    }

    public static function integrityFailure(): self
    {
        return new self(
            'ENTITLEMENT_QUOTA_INTEGRITY_FAILURE',
            500,
            'The entitlement quota data failed its integrity check.',
        );
    }

    public static function internal(): self
    {
        return new self(
            'ENTITLEMENT_QUOTA_INTERNAL_ERROR',
            500,
            'The entitlement quota operation could not be completed.',
        );
    }
}
