<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

use DateTimeImmutable;
use PeanutAdmin\IntegrationSecurity\Package;
use PeanutAdmin\IntegrationSecurity\Persistence\IntegrationSecurityRepository;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

final readonly class MachineIdentityService
{
    public function __construct(
        private IntegrationSecurityRepository $repository,
        private MachineScopeGrantPolicy $scopePolicy,
    ) {}

    /** @param list<string> $scopes */
    public function create(AuthorizedOperationContext $context, string $name, array $scopes, ?DateTimeImmutable $expiresAt): ProvisionedMachineIdentity
    {
        $this->assertOperation($context, 'machine-manage');
        [$name, $scopes, $expiresAt] = $this->validate($name, $scopes, $expiresAt);
        $this->scopePolicy->assertGrantable($context, $scopes);
        $token = self::token();
        $identityKey = 'machine_' . bin2hex(random_bytes(16));
        $identity = $this->repository->createMachine(
            $context->tenantContext,
            $identityKey,
            bin2hex(random_bytes(16)),
            $name,
            $scopes,
            substr($token, 0, 12),
            hash('sha256', $token),
            substr($token, -4),
            $expiresAt,
        );
        return new ProvisionedMachineIdentity($identity, $token);
    }

    /** @return list<MachineIdentity> */
    public function list(AuthorizedOperationContext $context): array
    {
        $this->assertOperation($context, 'machine-read');
        return $this->repository->machines($context->tenantContext->tenantId);
    }

    public function rotate(AuthorizedOperationContext $context, string $identityKey, int $expectedRevision): ProvisionedMachineIdentity
    {
        $this->assertOperation($context, 'machine-manage');
        $this->assertIdentityKey($identityKey);
        if ($expectedRevision < 1) {
            throw IntegrationSecurityException::invalid();
        }
        $current = null;
        foreach ($this->repository->machines($context->tenantContext->tenantId) as $identity) {
            if (hash_equals($identity->identityKey, $identityKey)) {
                $current = $identity;
            }
        }
        if (!$current instanceof MachineIdentity) {
            throw IntegrationSecurityException::machineNotFound();
        }
        $this->scopePolicy->assertGrantable($context, $current->scopes);
        $token = self::token();
        $successor = $this->repository->rotateMachine(
            $context->tenantContext,
            $identityKey,
            $expectedRevision,
            'machine_' . bin2hex(random_bytes(16)),
            $current->name,
            $current->scopes,
            substr($token, 0, 12),
            hash('sha256', $token),
            substr($token, -4),
            $current->expiresAt === null ? null : new DateTimeImmutable($current->expiresAt),
        );
        return new ProvisionedMachineIdentity($successor, $token);
    }

    public function revoke(AuthorizedOperationContext $context, string $identityKey, int $expectedRevision): MachineIdentity
    {
        $this->assertOperation($context, 'machine-manage');
        $this->assertIdentityKey($identityKey);
        if ($expectedRevision < 1) {
            throw IntegrationSecurityException::invalid();
        }
        return $this->repository->revokeMachine($context->tenantContext, $identityKey, $expectedRevision);
    }

    /** @param list<string> $requiredScopes */
    public function authenticate(string $token, array $requiredScopes, DateTimeImmutable $now): MachinePrincipal
    {
        if (preg_match('/^pa_mi_[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw IntegrationSecurityException::tokenInvalid();
        }
        $requiredScopes = $this->scopes($requiredScopes, true);
        $this->scopePolicy->assertKnown($requiredScopes);
        $digest = hash('sha256', $token);
        $row = $this->repository->machineByDigest($digest);
        if ($row === null || $row['status'] !== 'active') {
            throw IntegrationSecurityException::tokenInvalid();
        }
        if ($row['expires_at'] !== null && new DateTimeImmutable($row['expires_at']) <= $now) {
            throw IntegrationSecurityException::tokenExpired();
        }
        $this->scopePolicy->assertPersisted($row['scopes']);
        foreach ($requiredScopes as $scope) {
            if (!in_array($scope, $row['scopes'], true)) {
                throw IntegrationSecurityException::scopeDenied();
            }
        }
        $this->repository->touchMachine($digest, $now);
        return new MachinePrincipal($row['tenant_id'], $row['identity_key'], $row['scopes']);
    }

    /** @param list<string> $scopes @return array{string,list<string>,?DateTimeImmutable} */
    private function validate(string $name, array $scopes, ?DateTimeImmutable $expiresAt): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120 || ($expiresAt !== null && $expiresAt <= new DateTimeImmutable('now'))) {
            throw IntegrationSecurityException::invalid();
        }
        return [$name, $this->scopes($scopes, false), $expiresAt];
    }

    /** @param list<string> $scopes @return list<string> */
    private function scopes(array $scopes, bool $allowEmpty): array
    {
        $unique = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $scope) !== 1 || strlen($scope) > 96) {
                throw IntegrationSecurityException::invalid();
            }
            $unique[$scope] = true;
        }
        if ((!$allowEmpty && $unique === []) || count($unique) > 32) {
            throw IntegrationSecurityException::invalid();
        }
        $result = array_keys($unique);
        sort($result, SORT_STRING);
        return $result;
    }

    private function assertOperation(AuthorizedOperationContext $context, string $operation): void
    {
        if (!hash_equals(Package::RESOURCE_KEY, $context->resourceKey) || !hash_equals($operation, $context->operation)) {
            throw IntegrationSecurityException::denied();
        }
    }

    private function assertIdentityKey(string $key): void
    {
        if (preg_match('/^machine_[0-9a-f]{32}$/D', $key) !== 1) {
            throw IntegrationSecurityException::machineNotFound();
        }
    }

    private static function token(): string
    {
        return 'pa_mi_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
