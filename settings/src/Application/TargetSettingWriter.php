<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Application;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Host\AuthorizedExternalOperation;
use PeanutAdmin\Settings\Cache\ArrayRevisionedSettingCache;
use PeanutAdmin\Settings\Definition\SettingDefinition;
use PeanutAdmin\Settings\Persistence\PdoSettingRepository;
use PeanutAdmin\Settings\Secret\SecretProtector;
use PeanutAdmin\Settings\Secret\SecretStorageContext;

final readonly class TargetSettingWriter
{
    private SettingAdminService $admin;

    public function __construct(
        private PdoSettingRepository $repository,
        private SecretProtector $protector,
    ) {
        $this->admin = new SettingAdminService($repository, $protector);
    }

    public function replace(
        AuthorizedExternalOperation $authorized,
        SettingDefinition $definition,
        mixed $value,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
        ?DateTimeImmutable $asOf = null,
    ): EffectiveSetting {
        [$tenantId, $memberId, $targetResourceKey, $targetId] = $this->target(
            $authorized,
            $definition,
        );
        SettingAdminService::assertValidInterval($effectiveAt, $expiresAt);
        $storage = $this->admin->prepareStorage(
            $definition,
            $value,
            SecretStorageContext::target(
                $definition->qualifiedKey(),
                $tenantId,
                $targetResourceKey,
                $targetId,
            ),
        );

        return $this->repository->atomically(function () use (
            $authorized,
            $definition,
            $storage,
            $tenantId,
            $memberId,
            $targetResourceKey,
            $targetId,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
            $asOf,
        ): EffectiveSetting {
            $this->repository->writeTarget(
                $definition,
                'set',
                $storage,
                $tenantId,
                $memberId,
                $targetResourceKey,
                $targetId,
                $effectiveAt,
                $expiresAt,
                $ifMatch,
                $ifNoneMatch,
            );

            return $this->resolveTarget($authorized, $definition, $asOf);
        });
    }

    public function unset(
        AuthorizedExternalOperation $authorized,
        SettingDefinition $definition,
        DateTimeImmutable $effectiveAt,
        ?string $ifMatch,
        ?DateTimeImmutable $asOf = null,
    ): EffectiveSetting {
        SettingAdminService::assertValidInterval($effectiveAt, null);
        [$tenantId, $memberId, $targetResourceKey, $targetId] = $this->target(
            $authorized,
            $definition,
        );

        return $this->repository->atomically(function () use (
            $authorized,
            $definition,
            $tenantId,
            $memberId,
            $targetResourceKey,
            $targetId,
            $effectiveAt,
            $ifMatch,
            $asOf,
        ): EffectiveSetting {
            $this->repository->writeTarget(
                $definition,
                'unset',
                SettingAdminService::emptyStorage(),
                $tenantId,
                $memberId,
                $targetResourceKey,
                $targetId,
                $effectiveAt,
                null,
                $ifMatch,
                null,
            );

            return $this->resolveTarget($authorized, $definition, $asOf);
        });
    }

    private function resolveTarget(
        AuthorizedExternalOperation $authorized,
        SettingDefinition $definition,
        ?DateTimeImmutable $asOf,
    ): EffectiveSetting {
        $resolved = (new SettingResolver(
            $this->repository,
            $this->protector,
            new ArrayRevisionedSettingCache(),
        ))->resolveTarget(
            $definition,
            $authorized,
            $asOf ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        if (!$definition->secret) {
            return $resolved;
        }

        return new EffectiveSetting(
            $resolved->moduleKey,
            $resolved->settingKey,
            null,
            $resolved->source,
            $resolved->configured,
            $resolved->revision,
            $resolved->etag,
            $resolved->effectiveAt,
            $resolved->expiresAt,
            true,
        );
    }

    /** @return array{positive-int, positive-int, non-empty-string, non-empty-string} */
    private function target(
        AuthorizedExternalOperation $authorized,
        SettingDefinition $definition,
    ): array {
        $context = $authorized->context;
        $operation = $authorized->operation;
        if (!$context instanceof TenantContext
            || $context->tenantId < 1
            || $context->memberId < 1
            || !$definition->allows('target')
            || $definition->targetResourceKey === null
            || $definition->targetResourceKey === ''
            || $definition->targetOperation === null
            || $operation->audience !== 'tenant'
            || $operation->moduleKey !== $definition->moduleKey
            || $operation->operationId !== $definition->targetOperation
            || $operation->resourceKey !== $definition->targetResourceKey
            || $operation->dataAuthorization !== 'targets'
            || !in_array($operation->targetCardinality, ['one_required', 'zero_or_one'], true)
            || !$operation->atomicCommand
            || !$operation->idempotencyRequired
            || count($authorized->targets) !== 1) {
            throw SettingException::notFound('SETTING_TARGET_UNAUTHORIZED');
        }

        $target = $authorized->targets[0];
        if ($target->targetResourceKey !== $definition->targetResourceKey
            || count($target->targetIds) !== 1
            || $target->targetIds[0] === ''
            || strlen($target->targetIds[0]) > 128) {
            throw SettingException::notFound('SETTING_TARGET_UNAUTHORIZED');
        }

        return [
            $context->tenantId,
            $context->memberId,
            $target->targetResourceKey,
            $target->targetIds[0],
        ];
    }
}
