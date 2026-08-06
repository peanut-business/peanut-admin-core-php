<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Application;

use JsonException;
use PeanutAdmin\ImportExport\Contract\DataProviderRegistry;
use PeanutAdmin\ImportExport\Persistence\PdoImportExportRepository;
use PeanutAdmin\Kernel\Audit\AuditRepository;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\TaskJob\Application\TaskJobService;
use PeanutAdmin\TaskJob\Submission\TrustedJobPublisher;

final readonly class ImportExportService
{
    public const RESOURCE_KEY = 'peanut.import-export';
    public const TASK_TYPE = 'peanut.import-export.execute';

    public function __construct(
        private PdoImportExportRepository $repository,
        private DataProviderRegistry $providers,
        private TrustedJobPublisher $publisher,
        private TaskJobService $jobs,
        private AuditRepository $audit,
    ) {}

    /** @param array<string, string> $mapping */
    public function submitImport(AuthorizedOperationContext $context, string $providerKey, string $fileKey, array $mapping, string $idempotencyKey, int $retentionDays = 7): OperationRecord
    {
        self::assertOperation($context, 'create');
        self::assertFileKey($fileKey);
        return $this->submit($context, 'import', $providerKey, $fileKey, $mapping, $idempotencyKey, $retentionDays);
    }

    public function submitExport(AuthorizedOperationContext $context, string $providerKey, string $idempotencyKey, int $retentionDays = 7): OperationRecord
    {
        self::assertOperation($context, 'create');
        return $this->submit($context, 'export', $providerKey, null, [], $idempotencyKey, $retentionDays);
    }

    /** @return array{items: list<OperationRecord>, page: int, page_size: int, total: int} */
    public function list(AuthorizedOperationContext $context, string $status, int $page, int $pageSize): array
    {
        self::assertOperation($context, 'read');
        if (!in_array($status, ['queued', 'running', 'cancel_requested', 'succeeded', 'failed', 'cancelled', 'expired'], true)) {
            throw ImportExportException::invalid();
        }
        return $this->repository->list($context->tenantContext->tenantId, $status, $page, $pageSize);
    }

    public function detail(AuthorizedOperationContext $context, string $operationKey): OperationRecord
    {
        self::assertOperation($context, 'read');
        self::assertOperationKey($operationKey);
        return $this->repository->get($context->tenantContext->tenantId, $operationKey);
    }

    public function cancel(AuthorizedOperationContext $context, string $operationKey, int $revision): OperationRecord
    {
        self::assertOperation($context, 'cancel');
        self::assertOperationKey($operationKey);
        if ($revision < 1) {
            throw ImportExportException::invalid();
        }

        return $this->repository->transaction(function () use ($context, $operationKey, $revision): OperationRecord {
            $before = $this->repository->get($context->tenantContext->tenantId, $operationKey);
            $updated = $this->repository->requestCancel($context->tenantContext->tenantId, $operationKey, $revision);
            if ($before->status === 'queued' && $before->taskJobKey !== null) {
                $job = $this->jobs->detail($this->jobContext($context, 'read'), $before->taskJobKey);
                $this->jobs->cancel($this->jobContext($context, 'manage'), $before->taskJobKey, $job->revision);
            }
            $this->audit->appendTenantMember(
                $context->tenantContext,
                $updated->status === 'cancelled' ? 'tenant.import_export.cancelled' : 'tenant.import_export.cancel_requested',
                self::RESOURCE_KEY . '.cancel',
                'import_export_operation',
                $operationKey,
                targetCount: 1,
                metadata: ['direction' => $updated->direction, 'provider_key' => $updated->providerKey, 'revision' => $updated->revision],
            );
            return $updated;
        });
    }

    /** @param array<string, string> $mapping */
    private function submit(AuthorizedOperationContext $context, string $direction, string $providerKey, ?string $fileKey, array $mapping, string $idempotencyKey, int $retentionDays): OperationRecord
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $providerKey) !== 1
            || strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 160 || preg_match('/^[\x21-\x7e]+$/D', $idempotencyKey) !== 1
            || $retentionDays < 1 || $retentionDays > 90) {
            throw ImportExportException::invalid();
        }
        $provider = $this->providers->require($providerKey);
        $schema = $provider->schema();
        $mapping = $direction === 'import' ? $schema->validateImportMapping($mapping) : [];
        $requestHash = hash('sha256', $this->json([
            'direction' => $direction,
            'provider_key' => $providerKey,
            'input_file_key' => $fileKey,
            'schema_revision' => $schema->revision,
            'mapping' => $mapping,
            'retention_days' => $retentionDays,
        ]));

        return $this->repository->transaction(function () use ($context, $direction, $providerKey, $fileKey, $schema, $mapping, $idempotencyKey, $requestHash, $retentionDays): OperationRecord {
            $operation = $this->repository->create(
                $context->tenantContext->tenantId,
                $context->tenantContext->memberId,
                'iox_' . bin2hex(random_bytes(16)),
                $providerKey,
                $direction,
                $fileKey,
                $schema->revision,
                $mapping,
                hash('sha256', $idempotencyKey),
                $requestHash,
                $retentionDays,
            );
            if ($operation->taskJobKey !== null) {
                return $operation;
            }
            $job = $this->publisher->publish($context, self::TASK_TYPE, ['operation_key' => $operation->operationKey], 'iox-' . $idempotencyKey);
            $operation = $this->repository->attachJob($context->tenantContext->tenantId, $operation->operationKey, $job->jobKey);
            $this->audit->appendTenantMember(
                $context->tenantContext,
                'tenant.import_export.submitted',
                self::RESOURCE_KEY . '.create',
                'import_export_operation',
                $operation->operationKey,
                targetCount: 1,
                metadata: ['direction' => $direction, 'provider_key' => $providerKey, 'retention_days' => $retentionDays, 'revision' => $operation->revision],
            );
            return $operation;
        });
    }

    private function jobContext(AuthorizedOperationContext $source, string $operation): AuthorizedOperationContext
    {
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $source->tenantContext,
            TaskJobService::RESOURCE_KEY,
            $operation,
            $source->targets,
            hash('sha256', $source->authorizationBasisDigest . '|owned-import-export-job|' . $operation),
        ));
    }

    public static function assertOperation(AuthorizedOperationContext $context, string $operation): void
    {
        if (!hash_equals(self::RESOURCE_KEY, $context->resourceKey) || !hash_equals($operation, $context->operation)) {
            throw ImportExportException::denied();
        }
    }

    public static function assertOperationKey(string $key): void
    {
        if (preg_match('/^iox_[0-9a-f]{32}$/D', $key) !== 1) {
            throw ImportExportException::notFound();
        }
    }

    private static function assertFileKey(string $key): void
    {
        if (preg_match('/^file_[0-9a-f]{32}$/D', $key) !== 1) {
            throw ImportExportException::fileUnavailable();
        }
    }

    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw ImportExportException::invalid();
        }
    }
}
