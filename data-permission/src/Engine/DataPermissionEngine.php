<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Engine;

use PeanutAdmin\DataPermission\Catalog\OperationTargetType;
use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog;
use PeanutAdmin\DataPermission\Constraint\AlwaysTrue;
use PeanutAdmin\DataPermission\Constraint\AndConstraint;
use PeanutAdmin\DataPermission\Constraint\QueryConstraint;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Decision\AuthorizationDecision;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;
use PeanutAdmin\DataPermission\Policy\PolicyCache;
use PeanutAdmin\DataPermission\Policy\PolicyRepository;
use PeanutAdmin\DataPermission\Provider\ResourceProviderRegistry;
use PeanutAdmin\DataPermission\Provider\SharedMasterScopeProviderRegistry;
use PeanutAdmin\DataPermission\Target\TargetCardinalityValidator;
use PeanutAdmin\DataPermission\Target\TargetCatalogProviderRegistry;
use PeanutAdmin\DataPermission\Target\TargetCatalogQuery;
use PeanutAdmin\DataPermission\Target\TargetOptionPage;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;

final readonly class DataPermissionEngine
{
    public function __construct(
        private ResourceOperationCatalog $catalog,
        private PolicyRepository $policies,
        private PolicyCache $cache,
        private TenantAuthorizationEvaluator $permissions,
        private ResourceProviderRegistry $providers,
        private TargetResolverRegistry $targetResolvers,
        private TargetCatalogProviderRegistry $targetCatalogProviders,
        private SharedMasterScopeProviderRegistry $sharedMasterProviders,
        private TargetCardinalityValidator $cardinality = new TargetCardinalityValidator(),
    ) {}

    public function searchAllowedTargets(
        TenantContext $tenantContext,
        string $resourceKey,
        string $operationName,
        TargetCatalogQuery $query,
    ): TargetOptionPage {
        $operation = $this->operation($resourceKey, $operationName);
        $this->assertCommon($tenantContext, $operation);
        $targetType = $this->targetType(
            $operation,
            $query->targetRole,
            $query->targetResourceKey,
        );
        if ($query->mode === 'policy-config') {
            $required = array_filter([
                'core.role.data-policy.manage',
                $targetType->policySelectionPermissionKey,
            ]);
            foreach ($required as $permission) {
                if (!$this->permissions->allows($tenantContext, $permission)) {
                    throw new DataAuthorizationException(
                        'AUTHZ_PERMISSION_DENIED',
                        'The policy target selection permission is denied.',
                    );
                }
            }
            if ($targetType->policySelectionPermissionKey === null) {
                throw new DataAuthorizationException(
                    'AUTHZ_PERMISSION_DENIED',
                    'The operation does not declare a policy selection permission.',
                );
            }
        } elseif ($query->mode !== 'runtime') {
            throw new DataAuthorizationException(
                'AUTHZ_CONDITION_UNSUPPORTED',
                'The target catalog mode is not supported.',
            );
        }

        $policies = $this->effectivePolicies($tenantContext, $operation);

        return $this->targetCatalogProviders->get($targetType->catalogProviderKey)->searchAllowedTargets(
            new AuthorizationContext($tenantContext, $policies->primaryDepartmentId),
            $operation,
            $query,
            $policies,
        );
    }

    public function queryConstraint(
        TenantContext $tenantContext,
        string $resourceKey,
        string $operationName,
        TypedResourceTargetCollection $requestedTargets = new TypedResourceTargetCollection(),
    ): QueryConstraint {
        $operation = $this->operation($resourceKey, $operationName);
        $this->assertCommon($tenantContext, $operation);
        $resolvedTargets = $this->resolveTargets($tenantContext, $operation, $requestedTargets);
        $policies = $this->effectivePolicies($tenantContext, $operation);
        $context = new AuthorizationContext($tenantContext, $policies->primaryDepartmentId);
        $provider = $this->providers->query($operation->providerKey);

        if ($operation->accessMode === 'system_internal' || $operation->ownership === 'platform_internal') {
            throw new DataAuthorizationException('AUTHZ_SYSTEM_ACTOR_DENIED', 'Tenant members cannot use a system operation.');
        }
        if ($operation->accessMode === 'global_reference_read') {
            return new AlwaysTrue();
        }

        $dataConstraint = new AlwaysTrue();
        if ($operation->accessMode !== 'tenant_wide') {
            $targetsCoverPolicy = $resolvedTargets->sets !== []
                && $this->providers->target($operation->providerKey)->assertTargetsAllowed(
                    $context,
                    $operation,
                    $resolvedTargets,
                    $policies,
                )->allowed;
            $dataConstraint = $targetsCoverPolicy
                ? new AlwaysTrue()
                : $provider->compilePredicate($context, $operation, $policies);
        }
        $constraints = [$dataConstraint];
        if ($resolvedTargets->sets !== [] && $operation->ownership !== 'shared_master') {
            $constraints[] = $provider->requestedTargetConstraint($context, $operation, $resolvedTargets);
        }
        if (in_array($operation->ownership, ['tenant_owned', 'business_target_owned'], true)) {
            array_unshift($constraints, $provider->tenantConstraint($context, $operation));
        }
        if ($operation->ownership === 'shared_master') {
            $constraints[] = $this->sharedMasterProviders
                ->get($operation->resourceKey)
                ->compileVisiblePredicate($context, $operation, $resolvedTargets);
        }

        return count($constraints) === 1 ? $constraints[0] : new AndConstraint($constraints);
    }

    public function decideTargets(
        TenantContext $tenantContext,
        string $resourceKey,
        string $operationName,
        TypedResourceTargetCollection $requestedTargets,
        ?string $sharedResourceId = null,
    ): AuthorizationDecision {
        $operation = $this->operation($resourceKey, $operationName);
        $this->assertCommon($tenantContext, $operation);
        $resolvedTargets = $this->resolveTargets($tenantContext, $operation, $requestedTargets);
        $policies = $this->effectivePolicies($tenantContext, $operation);
        $context = new AuthorizationContext($tenantContext, $policies->primaryDepartmentId);

        $decision = $operation->accessMode === 'tenant_wide'
            ? AuthorizationDecision::allow()
            : $this->providers->target($operation->providerKey)->assertTargetsAllowed(
                $context,
                $operation,
                $resolvedTargets,
                $policies,
            );
        if (!$decision->allowed || $operation->ownership !== 'shared_master') {
            return $decision;
        }
        if ($sharedResourceId === null) {
            return AuthorizationDecision::deny('AUTHZ_SHARED_SCOPE_DENIED');
        }

        return $this->sharedMasterProviders->get($operation->resourceKey)->assertUsageAllowed(
            $context,
            $operation,
            $sharedResourceId,
            $resolvedTargets,
        );
    }

    public function decideCreate(
        TenantContext $tenantContext,
        string $resourceKey,
        string $operationName,
        TypedResourceTargetCollection $requestedTargets,
    ): AuthorizationDecision {
        $operation = $this->operation($resourceKey, $operationName);
        $this->assertCommon($tenantContext, $operation);
        $resolvedTargets = $this->resolveTargets($tenantContext, $operation, $requestedTargets);
        $policies = $this->effectivePolicies($tenantContext, $operation);
        $context = new AuthorizationContext($tenantContext, $policies->primaryDepartmentId);

        return $operation->accessMode === 'tenant_wide'
            ? AuthorizationDecision::allow()
            : $this->providers->create($operation->providerKey)->assertCreateAllowed(
                $context,
                $operation,
                $resolvedTargets,
                $policies,
            );
    }

    private function operation(string $resourceKey, string $operationName): ResourceOperation
    {
        return $this->catalog->find($resourceKey, $operationName)
            ?? throw new DataAuthorizationException(
                'AUTHZ_OPERATION_UNDECLARED',
                'The protected resource operation is not declared.',
            );
    }

    private function assertCommon(TenantContext $context, ResourceOperation $operation): void
    {
        if (!$this->catalog->moduleAvailable($context->tenantId, $operation->moduleKey)) {
            throw new DataAuthorizationException('AUTHZ_MODULE_UNAVAILABLE', 'The resource module is unavailable.');
        }
        if ($operation->permissionKeys === []) {
            throw new DataAuthorizationException('AUTHZ_PERMISSION_DENIED', 'The operation has no permission binding.');
        }
        $results = array_map(
            fn(string $permission): bool => $this->permissions->allows($context, $permission),
            $operation->permissionKeys,
        );
        $allowed = $operation->permissionMatch === 'all'
            ? !in_array(false, $results, true)
            : in_array(true, $results, true);
        if (!$allowed) {
            throw new DataAuthorizationException('AUTHZ_PERMISSION_DENIED', 'The functional permission is denied.');
        }
    }

    private function effectivePolicies(TenantContext $context, ResourceOperation $operation): EffectivePolicySet
    {
        $revision = $this->policies->revision($context->tenantId, $context->memberId, $operation->id);
        $key = implode(':', [
            'pa',
            'authz',
            'v1',
            'tenant',
            (string) $context->tenantId,
            'member',
            (string) $context->memberId,
            'registry',
            $this->catalog->registryRevision(),
            'operation',
            (string) $operation->id,
            'revision',
            $revision->value,
        ]);
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $policies = $this->policies->load($context->tenantId, $context->memberId, $operation->id);
        $this->cache->put($key, $policies, $revision->nextTransition);

        return $policies;
    }

    private function resolveTargets(
        TenantContext $context,
        ResourceOperation $operation,
        TypedResourceTargetCollection $targets,
    ): TypedResourceTargetCollection {
        $this->cardinality->validate($operation, $targets);
        if ($operation->targetCardinality !== 'none' && $operation->targetTypes === []) {
            throw new DataAuthorizationException(
                'AUTHZ_TARGET_TYPE_MISMATCH',
                'The operation has no declared target types.',
            );
        }

        $resolvedSets = [];
        foreach ($targets->sets as $targetSet) {
            $definition = $this->targetType($operation, $targetSet->targetRole, $targetSet->targetResourceKey);
            $resolved = $this->targetResolvers
                ->get($definition->resolverKey)
                ->resolveAndValidate($context, $targetSet);
            foreach ($resolved->targets->sets as $resolvedSet) {
                if (
                    $resolvedSet->targetRole !== $targetSet->targetRole
                    || $resolvedSet->targetResourceKey !== $targetSet->targetResourceKey
                ) {
                    throw new DataAuthorizationException(
                        'AUTHZ_TARGET_TYPE_MISMATCH',
                        'The target resolver changed the target type or role.',
                    );
                }
                $resolvedSets[] = $resolvedSet;
            }
        }

        return new TypedResourceTargetCollection($resolvedSets);
    }

    private function targetType(
        ResourceOperation $operation,
        string $targetRole,
        string $targetResourceKey,
    ): OperationTargetType {
        foreach ($operation->targetTypes as $targetType) {
            if (
                $targetType->targetRole === $targetRole
                && $targetType->targetResourceKey === $targetResourceKey
            ) {
                return $targetType;
            }
        }

        throw new DataAuthorizationException(
            'AUTHZ_TARGET_TYPE_MISMATCH',
            'The requested target type is not allowed for this operation.',
        );
    }
}
