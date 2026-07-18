<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Http;

use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\TenantAuthentication;
use PeanutAdmin\Kernel\Auth\TenantAuthService;
use PeanutAdmin\Kernel\Auth\TenantSelectionRequired;
use SensitiveParameter;

final readonly class TenantAuthEndpoint
{
    public function __construct(private TenantAuthService $auth) {}

    public function login(
        string $email,
        #[SensitiveParameter]
        string $password,
        ?string $tenantCode,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): TenantAuthResponse {
        return $this->outcome($this->auth->login(
            $email,
            $password,
            $tenantCode,
            $ipAddress,
            $userAgent,
            $requestId,
        ), $requestId);
    }

    public function selectTenant(
        #[SensitiveParameter]
        string $challengeToken,
        int $tenantId,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): TenantAuthResponse {
        return $this->authenticated($this->auth->selectTenant(
            $challengeToken,
            $tenantId,
            $ipAddress,
            $userAgent,
            $requestId,
        ), $requestId);
    }

    public function refresh(
        #[SensitiveParameter]
        string $refreshToken,
        bool $trustedOrigin,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): TenantAuthResponse {
        if (!$trustedOrigin) {
            throw new AuthException('AUTH_TOKEN_INVALID', 401);
        }

        return $this->authenticated($this->auth->refresh(
            $refreshToken,
            $ipAddress,
            $userAgent,
            $requestId,
        ), $requestId);
    }

    public function context(#[SensitiveParameter] string $accessToken, string $requestId): TenantAuthResponse
    {
        $context = $this->auth->context($accessToken, $requestId);

        return new TenantAuthResponse(200, [
            'data' => [
                'audience' => 'tenant',
                'account_id' => (string) $context->accountId,
                'tenant_id' => (string) $context->tenantId,
                'tenant_member_id' => (string) $context->memberId,
                'authorization_revision' => (string) $context->authorizationRevision,
            ],
            'meta' => ['request_id' => $requestId],
        ]);
    }

    public function switchChallenge(
        #[SensitiveParameter]
        string $accessToken,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): TenantAuthResponse {
        $selection = $this->auth->switchChallenge(
            $accessToken,
            $ipAddress,
            $userAgent,
            $requestId,
        );

        return new TenantAuthResponse(200, [
            'data' => $selection->responseData(),
            'meta' => ['request_id' => $requestId],
        ]);
    }

    public function logout(#[SensitiveParameter] string $accessToken, string $requestId): TenantAuthResponse
    {
        $this->auth->logout($accessToken, $requestId);

        return new TenantAuthResponse(204, null, [
            'Set-Cookie' => TenantRefreshCookie::clear($this->auth->client()),
        ]);
    }

    public function logoutAll(#[SensitiveParameter] string $accessToken, string $requestId): TenantAuthResponse
    {
        $this->auth->logoutAll($accessToken, $requestId);

        return new TenantAuthResponse(204, null, [
            'Set-Cookie' => TenantRefreshCookie::clear($this->auth->client()),
        ]);
    }

    private function outcome(
        TenantSelectionRequired|TenantAuthentication $outcome,
        string $requestId,
    ): TenantAuthResponse {
        if ($outcome instanceof TenantAuthentication) {
            return $this->authenticated($outcome, $requestId);
        }

        return new TenantAuthResponse(200, [
            'data' => $outcome->responseData(),
            'meta' => ['request_id' => $requestId],
        ]);
    }

    private function authenticated(
        TenantAuthentication $authentication,
        string $requestId,
    ): TenantAuthResponse {
        return new TenantAuthResponse(200, [
            'data' => $authentication->responseData(),
            'meta' => ['request_id' => $requestId],
        ], [
            'Set-Cookie' => TenantRefreshCookie::issue($this->auth->client(), $authentication->tokens->refresh),
        ]);
    }

    public function refreshCookieName(): string
    {
        return TenantRefreshCookie::name($this->auth->client());
    }
}
