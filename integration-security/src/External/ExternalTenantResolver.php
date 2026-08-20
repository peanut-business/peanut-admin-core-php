<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\External;

use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** Framework-agnostic resolver for a uniquely owned active external channel. */
final class ExternalTenantResolver
{
    public const ACTOR = 'peanut.external.callback';
    public const WECHAT_PAYMENT = 'payment.wechat';
    public const ALIPAY_PAYMENT = 'payment.alipay';
    public const WECHAT_OFFICIAL_CALLBACK = 'wechat.official-account';
    public const WECHAT_OFFICIAL_OAUTH = 'oauth.wechat.oa';
    public const WECHAT_OPEN_PLATFORM = 'oauth.wechat.open-pc';
    public const WECHAT_MINI_PROGRAM = 'oauth.wechat.mini-program';

    public function __construct(
        private readonly ExternalTenantBindingRepository $bindings,
        private readonly ExternalTenantAudit $audit,
    ) {}

    public function verifiedCallback(string $provider, string $callbackKey, string $operation, string $operationId, callable $verifier): ExternalTenantResolution
    {
        return $this->resolve($provider, $callbackKey, $operation, $operationId, fn(): array => $this->bindings->byCallbackKey($provider, $callbackKey), $verifier);
    }

    public function clientIdentity(string $provider, string $clientIdentity, string $operation, string $operationId): ExternalTenantResolution
    {
        $canonical = self::canonicalIdentity($clientIdentity);
        return $this->resolve($provider, $canonical, $operation, $operationId, fn(): array => $this->bindings->byClientIdentity($provider, hash('sha256', $canonical)));
    }

    public function onlyActiveBinding(string $provider, string $operation, string $operationId): ExternalTenantResolution
    {
        return $this->resolve($provider, 'server-only-active-binding', $operation, $operationId, fn(): array => $this->bindings->byProvider($provider));
    }

    public function oauthState(string $provider, string $state, string $operationId): ExternalTenantResolution
    {
        return $this->resolve($provider, $state, 'oauth.callback', $operationId, fn(): array => $this->bindings->byOAuthState($provider, hash('sha256', trim($state))));
    }

    public function oauthTicket(string $ticket, string $operationId): ExternalTenantResolution
    {
        return $this->resolve('oauth.wechat.completion', $ticket, 'oauth.complete', $operationId, fn(): array => $this->bindings->byOAuthTicket(hash('sha256', trim($ticket))), null, false);
    }

    public function bindingForTenant(int $tenantId, string $provider, bool $requireActive = true): ExternalTenantBinding
    {
        if ($tenantId < 1) {
            throw new ExternalTenantResolutionException();
        }
        return $this->oneAvailable($this->bindings->byTenant($provider, $tenantId), $provider, 'tenant:' . $tenantId, $requireActive);
    }

    public static function oauthProvider(string $scene): string
    {
        return match ($scene) {
            'mnp' => self::WECHAT_MINI_PROGRAM,
            'oa' => self::WECHAT_OFFICIAL_OAUTH,
            'open_pc' => self::WECHAT_OPEN_PLATFORM,
            default => throw new ExternalTenantResolutionException(),
        };
    }

    private function resolve(string $provider, string $candidate, string $operation, string $operationId, callable $lookup, ?callable $verifier = null, bool $requireProviderMatch = true): ExternalTenantResolution
    {
        $fingerprint = self::fingerprint($provider, $candidate);
        try {
            if (trim($provider) === '' || trim($candidate) === '' || trim($operationId) === '') {
                throw new \RuntimeException('invalid candidate');
            }
            $binding = $this->oneAvailable($lookup(), $provider, $candidate, true, $requireProviderMatch);
            $verifiedValue = $verifier === null ? null : $verifier($binding->config);
            if ($verifier !== null && $verifiedValue === false) {
                throw new \RuntimeException('provider verification failed');
            }
            $context = new TenantSystemContext($binding->tenantId, self::ACTOR, trim($operation), trim($operationId));
            $this->audit->record('accepted', ['provider' => $provider, 'identity' => $fingerprint, 'binding_id' => $binding->id, 'tenant_id' => $binding->tenantId, 'operation_id' => trim($operationId)]);
            return new ExternalTenantResolution($context, $binding, $verifiedValue);
        } catch (\Throwable) {
            $this->audit->record('rejected', ['provider' => trim($provider), 'identity' => $fingerprint, 'operation_id' => trim($operationId)]);
            throw new ExternalTenantResolutionException();
        }
    }

    /** @param list<ExternalTenantBinding> $bindings */
    private function oneAvailable(array $bindings, string $provider, string $candidate, bool $requireActive = true, bool $requireProviderMatch = true): ExternalTenantBinding
    {
        if (count($bindings) !== 1) {
            throw new ExternalTenantResolutionException();
        }
        $binding = $bindings[0];
        if ($binding->id < 1 || $binding->tenantId < 1 || ($requireActive && !$binding->active) || !$binding->tenantActive || ($requireProviderMatch && !hash_equals($provider, $binding->provider))) {
            throw new ExternalTenantResolutionException();
        }
        return $binding;
    }

    private static function canonicalIdentity(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > 191) {
            throw new ExternalTenantResolutionException();
        }
        return $value;
    }

    private static function fingerprint(string $provider, string $candidate): string
    {
        return substr(hash('sha256', trim($provider) . "\0" . trim($candidate)), 0, 16);
    }
}
