<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Identity\CredentialStatus;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Persistence\TransactionManager;
use PeanutAdmin\Kernel\Platform\PlatformOperatorStatus;
use SensitiveParameter;

final class PlatformAuthService
{
    private readonly string $dummyPasswordHash;

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly PlatformAuthRepository $repository,
        private readonly PasswordHasher $passwords,
        private readonly Clock $clock,
        private readonly TokenIssuer $tokens,
    ) {
        $this->dummyPasswordHash = $passwords->hash('peanut-admin-platform-invalid-pad');
    }

    public function login(
        string $email,
        #[SensitiveParameter]
        string $plainPassword,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): PlatformAuthentication {
        $normalizedEmail = EmailAddress::fromString($email);
        $now = $this->clock->now();
        $result = $this->transactions->run(function () use (
            $normalizedEmail,
            $plainPassword,
            $ipAddress,
            $userAgent,
            $requestId,
            $now,
        ): PlatformAuthentication|AuthException {
            $principal = $this->repository->principalByEmail($normalizedEmail, true);
            $hash = $principal === null ? $this->dummyPasswordHash : $principal->secretHash;
            if (
                !$this->passwords->verify($plainPassword, $hash)
                || $principal === null
                || !$this->principalIsActive($principal)
            ) {
                $this->repository->recordEvent(
                    'login_failed',
                    'denied',
                    'invalid_credentials',
                    $principal?->accountId,
                    $principal?->credentialId,
                    null,
                    $requestId,
                    $ipAddress,
                    $this->userAgentHash($userAgent),
                    $now,
                );

                return new AuthException('AUTH_INVALID_CREDENTIALS', 401);
            }

            $tokens = $this->issuePair($now, $now->modify('+14 days'));
            $session = $this->repository->createSession(
                $principal,
                $this->tokens->key($now),
                $tokens,
                $ipAddress,
                $this->userAgentHash($userAgent),
                $now,
            );
            $this->repository->recordEvent(
                'login_succeeded',
                'success',
                null,
                $principal->accountId,
                $principal->credentialId,
                $session->sessionKey,
                $requestId,
                $ipAddress,
                $this->userAgentHash($userAgent),
                $now,
            );

            return new PlatformAuthentication(
                $tokens,
                PlatformContext::fromValidatedSession($session, $requestId),
            );
        });

        if ($result instanceof AuthException) {
            throw $result;
        }

        return $result;
    }

    public function context(
        #[SensitiveParameter]
        string $accessToken,
        string $requestId,
    ): PlatformContext {
        return PlatformContext::fromValidatedSession(
            $this->validatedAccessSession($accessToken),
            $requestId,
        );
    }

    public function refresh(
        #[SensitiveParameter]
        string $refreshToken,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): PlatformAuthentication {
        $this->assertPlatformPrefix($refreshToken, 'pa_prt_');
        $now = $this->clock->now();
        $result = $this->transactions->run(function () use (
            $refreshToken,
            $ipAddress,
            $userAgent,
            $requestId,
            $now,
        ): PlatformAuthentication|AuthException {
            $record = $this->repository->sessionByTokenHash(
                hash('sha256', $refreshToken),
                'refresh',
                true,
            );
            if ($record === null) {
                return new AuthException('AUTH_TOKEN_INVALID', 401);
            }
            if ($record->tokenStatus === 'used') {
                $this->repository->revokeSession($record->sessionId, 'refresh_reused', $now);

                return new AuthException('AUTH_REFRESH_REUSED', 401);
            }
            $failure = $this->tokenFailure($record, 'refresh', $now)
                ?? $this->sessionFailure($record, $now);
            if ($failure !== null) {
                $this->repository->revokeSession($record->sessionId, 'session_invalid', $now);

                return $failure;
            }

            $tokens = $this->issuePair($now, $record->absoluteExpiresAt);
            $this->repository->rotateTokens($record, $tokens, $now);
            $this->repository->recordEvent(
                'token_refreshed',
                'success',
                null,
                $record->accountId,
                null,
                $record->sessionKey,
                $requestId,
                $ipAddress,
                $this->userAgentHash($userAgent),
                $now,
            );

            return new PlatformAuthentication(
                $tokens,
                PlatformContext::fromValidatedSession($record->validated(), $requestId),
            );
        });

        if ($result instanceof AuthException) {
            throw $result;
        }

        return $result;
    }

    public function logout(#[SensitiveParameter] string $accessToken): void
    {
        $session = $this->validatedAccessSession($accessToken);
        $now = $this->clock->now();
        $this->transactions->run(function () use ($session, $now): void {
            $this->repository->revokeSession($session->sessionId, 'logout', $now);
        });
    }

    private function validatedAccessSession(string $accessToken): ValidatedPlatformSession
    {
        $this->assertPlatformPrefix($accessToken, 'pa_pat_');
        $now = $this->clock->now();
        $result = $this->transactions->run(function () use ($accessToken, $now): ValidatedPlatformSession|AuthException {
            $record = $this->repository->sessionByTokenHash(
                hash('sha256', $accessToken),
                'access',
                true,
            );
            if ($record === null) {
                return new AuthException('AUTH_TOKEN_INVALID', 401);
            }
            $tokenFailure = $this->tokenFailure($record, 'access', $now);
            if ($tokenFailure !== null) {
                return $tokenFailure;
            }
            $failure = $this->sessionFailure($record, $now);
            if ($failure !== null) {
                $this->repository->revokeSession($record->sessionId, 'session_invalid', $now);

                return $failure;
            }

            return $record->validated();
        });

        if ($result instanceof AuthException) {
            throw $result;
        }

        return $result;
    }

    private function principalIsActive(PlatformAuthPrincipal $principal): bool
    {
        return $principal->credentialStatus === CredentialStatus::Active
            && $principal->accountStatus === AccountStatus::Active
            && $principal->operatorStatus === PlatformOperatorStatus::Active;
    }

    private function sessionFailure(
        PlatformSessionAuthenticationRecord $record,
        DateTimeImmutable $now,
    ): ?AuthException {
        if (
            $now >= $record->idleExpiresAt
            || $now >= $record->absoluteExpiresAt
        ) {
            return new AuthException('AUTH_SESSION_EXPIRED', 401);
        }
        if ($record->sessionStatus !== 'active') {
            return new AuthException('AUTH_SESSION_REVOKED', 401);
        }
        if (
            $record->accountStatus !== AccountStatus::Active
            || $record->accountSecurityRevision !== $record->currentAccountSecurityRevision
            || $record->operatorStatus !== PlatformOperatorStatus::Active
            || $record->operatorSecurityRevision !== $record->currentOperatorSecurityRevision
        ) {
            return new AuthException('AUTH_ACCOUNT_UNAVAILABLE', 401);
        }

        return null;
    }

    private function tokenFailure(
        PlatformSessionAuthenticationRecord $record,
        string $expectedType,
        DateTimeImmutable $now,
    ): ?AuthException {
        if ($record->tokenType !== $expectedType || $record->tokenStatus !== 'active') {
            return new AuthException('AUTH_TOKEN_INVALID', 401);
        }
        if ($now >= $record->tokenExpiresAt) {
            return new AuthException('AUTH_SESSION_EXPIRED', 401);
        }

        return null;
    }

    private function issuePair(DateTimeImmutable $now, DateTimeImmutable $absolute): PlatformTokenPair
    {
        $accessExpiresAt = $now->modify('+15 minutes');
        if ($accessExpiresAt > $absolute) {
            $accessExpiresAt = $absolute;
        }

        return new PlatformTokenPair(
            $this->tokens->platformAccess(),
            $this->tokens->platformRefresh(),
            $accessExpiresAt,
            $absolute,
        );
    }

    private function assertPlatformPrefix(string $token, string $expected): void
    {
        if (str_starts_with($token, 'pa_tat_') || str_starts_with($token, 'pa_trt_')) {
            throw new AuthException('AUTH_AUDIENCE_MISMATCH', 401);
        }
        if (!str_starts_with($token, $expected)) {
            throw new AuthException('AUTH_TOKEN_INVALID', 401);
        }
    }

    private function userAgentHash(?string $userAgent): ?string
    {
        return $userAgent === null ? null : hash('sha256', $userAgent);
    }
}
