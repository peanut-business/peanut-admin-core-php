<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

/**
 * A trusted execution envelope passed to a Module after an upstream identity boundary succeeds.
 */
final readonly class ModuleExecutionContext
{
    public function __construct(
        public string $moduleKey,
        public int $tenantId,
        public string $actorKey,
        public string $operation,
        public string $operationId,
        public TenantContext|AuthenticatedMemberContext|TenantSystemContext|TenantScope $subject,
    ) {}

    public static function admin(string $moduleKey, TenantContext $context, string $operation): self
    {
        self::assertModuleKey($moduleKey);
        self::assertOperation($operation, $context->requestId);
        if ($context->tenantId < 1 || $context->accountId < 1 || $context->memberId < 1
            || $context->authorizationRevision < 1 || $context->sessionKey === ''
            || $context->clientKey === '' || $context->requestId === '') {
            throw new AuthException('CONTEXT_TENANT_REQUIRED', 403);
        }

        return new self(
            $moduleKey,
            $context->tenantId,
            'tenant.member',
            trim($operation),
            $context->requestId,
            $context,
        );
    }

    public static function businessMember(
        string $moduleKey,
        AuthenticatedMemberContext $context,
        string $operation,
    ): self {
        self::assertModuleKey($moduleKey);
        self::assertOperation($operation, $context->requestId);

        return new self(
            $moduleKey,
            $context->tenantId,
            'business.member',
            trim($operation),
            $context->requestId,
            $context,
        );
    }

    public static function system(string $moduleKey, TenantSystemContext $context): self
    {
        self::assertModuleKey($moduleKey);
        self::assertOperation($context->operation, $context->operationId);
        if ($context->tenantId < 1 || trim($context->actorKey) === '') {
            throw new AuthException('CONTEXT_TENANT_REQUIRED', 403);
        }

        return new self(
            $moduleKey,
            $context->tenantId,
            trim($context->actorKey),
            trim($context->operation),
            trim($context->operationId),
            $context,
        );
    }

    public static function scheduled(string $moduleKey, TenantScope $scope, string $operation): self
    {
        self::assertModuleKey($moduleKey);
        self::assertOperation($operation, $scope->contextIdentity());

        return new self(
            $moduleKey,
            $scope->tenantId(),
            'tenant.scheduler',
            trim($operation),
            $scope->contextIdentity(),
            $scope,
        );
    }

    public function isAdminMember(): bool
    {
        return $this->subject instanceof TenantContext;
    }

    private static function assertModuleKey(string $moduleKey): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,127}$/D', trim($moduleKey)) !== 1) {
            throw new AuthException('MODULE_CONTEXT_INVALID', 403);
        }
    }

    private static function assertOperation(string $operation, string $operationId): void
    {
        if (trim($operation) === '' || trim($operationId) === '') {
            throw new AuthException('MODULE_CONTEXT_INVALID', 403);
        }
    }
}
