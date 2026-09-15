<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;

final readonly class PlatformContext
{
    private function __construct(
        public int $accountId,
        public int $operatorId,
        public string $sessionKey,
        public string $clientKey,
        public string $requestId,
        public DateTimeImmutable $issuedAt,
    ) {}

    public static function fromValidatedSession(
        ValidatedPlatformSession $session,
        string $requestId,
    ): self {
        return new self(
            $session->accountId,
            $session->operatorId,
            $session->sessionKey,
            $session->clientKey,
            $requestId,
            $session->issuedAt,
        );
    }

    /**
     * Creates an execution context for a trusted, non-interactive application
     * entry point. Business services still verify that the referenced operator
     * is active before performing mutations.
     */
    public static function fromTrustedAutomation(
        int $accountId,
        int $operatorId,
        string $clientKey,
        string $requestId,
        DateTimeImmutable $issuedAt,
    ): self {
        if ($accountId < 1 || $operatorId < 1 || trim($clientKey) === '' || trim($requestId) === '') {
            throw new \InvalidArgumentException('Trusted Platform automation context is incomplete.');
        }

        return new self($accountId, $operatorId, '', $clientKey, $requestId, $issuedAt);
    }
}
