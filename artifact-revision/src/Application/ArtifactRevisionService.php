<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Application;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\ArtifactRevision\Model\ArtifactRevision;
use PeanutAdmin\ArtifactRevision\Package;
use PeanutAdmin\ArtifactRevision\Persistence\ArtifactRevisionRepository;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;
use PeanutAdmin\Kernel\Idempotency\PdoIdempotencyRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoAuditRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoTransactionManager;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final readonly class ArtifactRevisionService
{
    private PdoTransactionManager $transactions;
    private PdoIdempotencyRepository $idempotency;
    private PdoAuditRepository $audit;

    public function __construct(private ArtifactRevisionRepository $repository)
    {
        $pdo = $repository->connection();
        $this->transactions = new PdoTransactionManager($pdo);
        $this->idempotency = new PdoIdempotencyRepository($pdo);
        $this->audit = new PdoAuditRepository($pdo);
    }

    public function createRevision(
        AuthorizedOperationContext $context,
        string $artifactType,
        string $artifactKey,
        ?string $parentRevisionKey,
        ?int $expectedArtifactRevision,
        string $idempotencyKey,
    ): ArtifactRevisionReceipt {
        $this->assertContext($context, $artifactType, $artifactKey);
        $this->assertArtifactIdentity($artifactType, $artifactKey);
        if ($parentRevisionKey !== null) {
            $this->assertRevisionKey($parentRevisionKey);
        }
        if ($expectedArtifactRevision !== null) {
            $this->assertPositive($expectedArtifactRevision);
        }

        return $this->command(
            $context,
            Package::CREATE_OPERATION,
            [
                'artifact_type' => $artifactType,
                'artifact_key' => $artifactKey,
                'parent_revision_key' => $parentRevisionKey,
                'expected_artifact_revision' => $expectedArtifactRevision,
            ],
            $idempotencyKey,
            function (string $now) use (
                $context,
                $artifactType,
                $artifactKey,
                $parentRevisionKey,
                $expectedArtifactRevision,
            ): ArtifactRevisionReceipt {
                $artifact = $this->repository->lockOrCreateArtifact(
                    $context->tenantContext->tenantId,
                    $artifactType,
                    $artifactKey,
                    $context->tenantContext->memberId,
                    $expectedArtifactRevision,
                    $now,
                );
                $parent = null;
                if ($parentRevisionKey !== null) {
                    $parent = $this->repository->revision(
                        $context->tenantContext->tenantId,
                        $artifactType,
                        $artifactKey,
                        $parentRevisionKey,
                        true,
                    );
                    if ($parent === null || !$parent->isFinalized()) {
                        throw ArtifactRevisionException::notFound();
                    }
                }
                $revision = $this->repository->createPendingRevision(
                    $context->tenantContext->tenantId,
                    $artifact->id,
                    'revision_' . bin2hex(random_bytes(16)),
                    $parent?->id,
                    $artifact->revision,
                    $context->tenantContext->memberId,
                    $now,
                );
                $this->appendAudit($context, 'tenant.artifact_revision.created', $revision);

                return $this->receipt(Package::CREATE_OPERATION, $artifact->revision + 1, $revision);
            },
        );
    }

    public function finalizeRevision(
        AuthorizedOperationContext $context,
        string $artifactType,
        string $artifactKey,
        string $revisionKey,
        int $expectedArtifactRevision,
        int $expectedRevision,
        string $payloadSchemaKey,
        string $payloadSchemaVersion,
        string $payloadRef,
        string $payloadSha256,
        ?string $attachmentManifestSha256,
        string $idempotencyKey,
    ): ArtifactRevisionReceipt {
        $this->assertContext($context, $artifactType, $artifactKey);
        $this->assertArtifactIdentity($artifactType, $artifactKey);
        $this->assertRevisionKey($revisionKey);
        $this->assertPositive($expectedArtifactRevision);
        $this->assertPositive($expectedRevision);
        $this->assertIdentifier($payloadSchemaKey, 64);
        $this->assertAscii($payloadSchemaVersion, 32);
        $this->assertAscii($payloadRef, 512);
        $this->assertDigest($payloadSha256);
        if ($attachmentManifestSha256 !== null) {
            $this->assertDigest($attachmentManifestSha256);
        }

        return $this->command(
            $context,
            Package::FINALIZE_OPERATION,
            [
                'artifact_type' => $artifactType,
                'artifact_key' => $artifactKey,
                'revision_key' => $revisionKey,
                'expected_artifact_revision' => $expectedArtifactRevision,
                'expected_revision' => $expectedRevision,
                'payload_schema_key' => $payloadSchemaKey,
                'payload_schema_version' => $payloadSchemaVersion,
                'payload_ref' => $payloadRef,
                'payload_sha256' => $payloadSha256,
                'attachment_manifest_sha256' => $attachmentManifestSha256,
            ],
            $idempotencyKey,
            function (string $now) use (
                $context,
                $artifactType,
                $artifactKey,
                $revisionKey,
                $expectedArtifactRevision,
                $expectedRevision,
                $payloadSchemaKey,
                $payloadSchemaVersion,
                $payloadRef,
                $payloadSha256,
                $attachmentManifestSha256,
            ): ArtifactRevisionReceipt {
                $artifact = $this->repository->artifact(
                    $context->tenantContext->tenantId,
                    $artifactType,
                    $artifactKey,
                    true,
                ) ?? throw ArtifactRevisionException::notFound();
                if ($artifact->revision !== $expectedArtifactRevision) {
                    throw ArtifactRevisionException::conflict();
                }
                $revision = $this->repository->revision(
                    $context->tenantContext->tenantId,
                    $artifactType,
                    $artifactKey,
                    $revisionKey,
                    true,
                ) ?? throw ArtifactRevisionException::notFound();
                if ($revision->isFinalized() || $revision->revision !== $expectedRevision) {
                    throw ArtifactRevisionException::conflict();
                }
                $finalized = $this->repository->finalizeRevision(
                    $context->tenantContext->tenantId,
                    $artifact->id,
                    $revisionKey,
                    $expectedArtifactRevision,
                    $expectedRevision,
                    $context->tenantContext->memberId,
                    $payloadSchemaKey,
                    $payloadSchemaVersion,
                    $payloadRef,
                    $payloadSha256,
                    $attachmentManifestSha256,
                    $now,
                );
                $this->appendAudit($context, 'tenant.artifact_revision.finalized', $finalized);

                return $this->receipt(Package::FINALIZE_OPERATION, $artifact->revision + 1, $finalized);
            },
        );
    }

    public function revision(
        AuthorizedOperationContext $context,
        string $artifactType,
        string $artifactKey,
        string $revisionKey,
    ): ArtifactRevision {
        $this->assertContext($context, $artifactType, $artifactKey);
        $this->assertArtifactIdentity($artifactType, $artifactKey);
        $this->assertRevisionKey($revisionKey);

        try {
            return $this->repository->revision(
                $context->tenantContext->tenantId,
                $artifactType,
                $artifactKey,
                $revisionKey,
            ) ?? throw ArtifactRevisionException::notFound();
        } catch (ArtifactRevisionException $exception) {
            throw $exception;
        } catch (UnexpectedValueException) {
            throw ArtifactRevisionException::integrityFailure();
        } catch (Throwable) {
            throw ArtifactRevisionException::internal();
        }
    }

    public function compare(
        AuthorizedOperationContext $context,
        string $artifactType,
        string $artifactKey,
        string $leftRevisionKey,
        string $rightRevisionKey,
    ): ArtifactRevisionComparison {
        $this->assertContext($context, $artifactType, $artifactKey);
        $this->assertArtifactIdentity($artifactType, $artifactKey);
        $this->assertRevisionKey($leftRevisionKey);
        $this->assertRevisionKey($rightRevisionKey);

        try {
            $left = $this->repository->revision(
                $context->tenantContext->tenantId,
                $artifactType,
                $artifactKey,
                $leftRevisionKey,
            ) ?? throw ArtifactRevisionException::notFound();
            $right = $this->repository->revision(
                $context->tenantContext->tenantId,
                $artifactType,
                $artifactKey,
                $rightRevisionKey,
            ) ?? throw ArtifactRevisionException::notFound();
            if (!$left->isFinalized() || !$right->isFinalized()) {
                throw ArtifactRevisionException::conflict();
            }
            $relationship = 'diverged';
            if ($left->id === $right->id) {
                $relationship = 'same';
            } elseif ($this->isAncestor($context, $left, $right)) {
                $relationship = 'ancestor';
            } elseif ($this->isAncestor($context, $right, $left)) {
                $relationship = 'descendant';
            }

            return new ArtifactRevisionComparison(
                $artifactType,
                $artifactKey,
                $leftRevisionKey,
                $rightRevisionKey,
                $relationship,
            );
        } catch (ArtifactRevisionException $exception) {
            throw $exception;
        } catch (UnexpectedValueException) {
            throw ArtifactRevisionException::integrityFailure();
        } catch (Throwable) {
            throw ArtifactRevisionException::internal();
        }
    }

    /**
     * @template T of ArtifactRevisionReceipt
     * @param array<string, int|string|null> $semanticInputs
     * @param callable(string): T $operation
     * @return T
     */
    private function command(
        AuthorizedOperationContext $context,
        string $operationKey,
        array $semanticInputs,
        string $rawIdempotencyKey,
        callable $operation,
    ): ArtifactRevisionReceipt {
        try {
            $key = IdempotencyKey::fromString($rawIdempotencyKey);
            $requestHash = hash('sha256', $this->canonicalJson($semanticInputs));
            $comparisonTime = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $expiresAt = $comparisonTime->modify('+1 day');

            return $this->transactions->run(function () use (
                $context,
                $operationKey,
                $key,
                $requestHash,
                $comparisonTime,
                $expiresAt,
                $operation,
            ): ArtifactRevisionReceipt {
                $record = $this->idempotency->beginTenant(
                    $context->tenantContext->tenantId,
                    $context->tenantContext->memberId,
                    $operationKey,
                    $key,
                    $requestHash,
                    $expiresAt,
                    $comparisonTime,
                );
                if ($record->replayable()) {
                    return ArtifactRevisionReceipt::fromArray((array) $record->responseBody, $operationKey);
                }
                if (!$record->acquiredForExecution()) {
                    throw new ApiException(
                        'IDEMPOTENCY_REQUEST_PROCESSING',
                        409,
                        'Another artifact revision request with this Idempotency-Key is processing.',
                    );
                }
                $receipt = $operation($comparisonTime->format('Y-m-d H:i:s.v'));
                $this->idempotency->completeTenant(
                    $record->id,
                    200,
                    $receipt->toArray(),
                    'artifact_revision',
                    $receipt->revisionKey,
                );

                return $receipt;
            });
        } catch (ArtifactRevisionException|ApiException $exception) {
            throw $exception;
        } catch (UnexpectedValueException) {
            throw ArtifactRevisionException::integrityFailure();
        } catch (RuntimeException $exception) {
            throw $this->mapRepositoryFailure($exception);
        } catch (Throwable) {
            throw ArtifactRevisionException::internal();
        }
    }

    private function receipt(
        string $operation,
        int $artifactRevision,
        ArtifactRevision $revision,
    ): ArtifactRevisionReceipt {
        return new ArtifactRevisionReceipt(
            $operation,
            $revision->artifactType,
            $revision->artifactKey,
            $artifactRevision,
            $revision->revisionKey,
            $revision->revisionNumber,
            $revision->parentRevisionKey,
            $revision->state,
            $revision->revision,
            $revision->canonicalEnvelopeSha256,
        );
    }

    private function appendAudit(
        AuthorizedOperationContext $context,
        string $eventType,
        ArtifactRevision $revision,
    ): void {
        $this->audit->appendTenantMember(
            $context->tenantContext,
            $eventType,
            $context->resourceKey . '.' . $context->operation,
            $revision->artifactType,
            $revision->artifactKey,
            targetCount: 1,
            metadata: [
                'artifact_type' => $revision->artifactType,
                'artifact_key' => $revision->artifactKey,
                'revision_key' => $revision->revisionKey,
                'revision_number' => $revision->revisionNumber,
                'parent_revision_key' => $revision->parentRevisionKey,
                'state' => $revision->state,
                'canonical_envelope_sha256' => $revision->canonicalEnvelopeSha256,
            ],
        );
    }

    private function isAncestor(
        AuthorizedOperationContext $context,
        ArtifactRevision $possibleAncestor,
        ArtifactRevision $descendant,
    ): bool {
        $visited = [$descendant->id => true];
        $current = $descendant;
        $remaining = $descendant->revisionNumber;
        while ($current->parentRevisionId !== null) {
            if ($remaining-- < 1 || isset($visited[$current->parentRevisionId])) {
                throw ArtifactRevisionException::integrityFailure();
            }
            $parent = $this->repository->revisionById(
                $context->tenantContext->tenantId,
                $descendant->artifactId,
                $current->parentRevisionId,
            );
            if ($parent === null
                || !$parent->isFinalized()
                || $parent->revisionNumber >= $current->revisionNumber) {
                throw ArtifactRevisionException::integrityFailure();
            }
            if ($parent->id === $possibleAncestor->id) {
                return true;
            }
            $visited[$parent->id] = true;
            $current = $parent;
        }

        return false;
    }

    private function assertContext(
        AuthorizedOperationContext $context,
        string $artifactType,
        string $artifactKey,
    ): void {
        if ($context->tenantContext->tenantId < 1
            || $context->tenantContext->memberId < 1
            || $context->tenantContext->accountId < 1
            || $context->tenantContext->requestId === ''
            || $context->resourceKey === ''
            || $context->operation === '') {
            throw ArtifactRevisionException::invalid();
        }
        $targets = array_values($context->targets);
        $target = $targets[0] ?? null;
        if (!hash_equals($context->resourceKey, $artifactType)
            || count($targets) !== 1
            || $target === null
            || $target->targetRole !== 'primary'
            || !hash_equals($target->targetResourceKey, $artifactType)
            || count($target->targetIds) !== 1
            || !hash_equals($target->targetIds[0], $artifactKey)) {
            throw ArtifactRevisionException::notFound();
        }
    }

    private function assertArtifactIdentity(string $artifactType, string $artifactKey): void
    {
        $this->assertIdentifier($artifactType, 64);
        $this->assertAscii($artifactKey, 128);
    }

    private function assertIdentifier(string $value, int $maximumLength): void
    {
        if (strlen($value) > $maximumLength
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $value) !== 1) {
            throw ArtifactRevisionException::invalid();
        }
    }

    private function assertAscii(string $value, int $maximumLength): void
    {
        if (strlen($value) > $maximumLength || preg_match('/^[\x21-\x7E]+$/D', $value) !== 1) {
            throw ArtifactRevisionException::invalid();
        }
    }

    private function assertRevisionKey(string $revisionKey): void
    {
        if (preg_match('/^revision_[0-9a-f]{32}$/D', $revisionKey) !== 1) {
            throw ArtifactRevisionException::invalid();
        }
    }

    private function assertDigest(string $digest): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
            throw ArtifactRevisionException::invalid();
        }
    }

    private function assertPositive(int $value): void
    {
        if ($value < 1) {
            throw ArtifactRevisionException::invalid();
        }
    }

    /** @param array<string, int|string|null> $value */
    private function canonicalJson(array $value): string
    {
        ksort($value, SORT_STRING);
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw ArtifactRevisionException::internal();
        }
    }

    private function mapRepositoryFailure(RuntimeException $exception): ArtifactRevisionException
    {
        return match ($exception->getMessage()) {
            'The artifact is unavailable.',
            'The artifact parent revision is unavailable.',
            'The artifact revision is unavailable.' => ArtifactRevisionException::notFound(),
            'The artifact revision has changed.',
            'The artifact identity is unavailable.',
            'The artifact identity already exists.',
            'The artifact parent revision is not earlier than the new revision.',
            'The artifact revision identity already exists.',
            'The artifact revision is immutable.' => ArtifactRevisionException::conflict(),
            default => ArtifactRevisionException::internal(),
        };
    }
}
