<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Application;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetDefinition;
use PeanutAdmin\ReferenceCodes\Persistence\PdoReferenceCodeRepository;

final readonly class ReferenceCodeAdminService
{
    public function __construct(private PdoReferenceCodeRepository $repository) {}

    /** @param array<array-key, mixed> $metadata */
    public function create(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code,
        string $label,
        array $metadata,
        string $status,
        int $sortOrder,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifNoneMatch,
    ): EffectiveReferenceCode {
        $this->assertCreatePrecondition($ifNoneMatch);
        $code = $this->code($code);
        [$label, , $metadataJson] = $this->versionInput(
            $label,
            $metadata,
            $status,
            $sortOrder,
            $effectiveAt,
            $expiresAt,
        );

        return $this->repository->atomically(function () use (
            $definition,
            $context,
            $code,
            $label,
            $metadataJson,
            $status,
            $sortOrder,
            $effectiveAt,
            $expiresAt,
        ): EffectiveReferenceCode {
            $comparisonTime = $this->repository->create(
                $definition,
                $context,
                $code,
                $label,
                $metadataJson,
                $status,
                $sortOrder,
                $effectiveAt,
                $expiresAt,
            );

            return (new ReferenceCodeQuery($this->repository))->get(
                $definition,
                $context,
                $code,
                $comparisonTime,
            );
        });
    }

    /** @param array<array-key, mixed> $metadata */
    public function replace(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code,
        string $label,
        array $metadata,
        string $status,
        int $sortOrder,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
    ): EffectiveReferenceCode {
        $expectedRevision = $this->strongRevision($ifMatch);
        $code = $this->code($code);
        [$label, , $metadataJson] = $this->versionInput(
            $label,
            $metadata,
            $status,
            $sortOrder,
            $effectiveAt,
            $expiresAt,
        );

        return $this->repository->atomically(function () use (
            $definition,
            $context,
            $code,
            $label,
            $metadataJson,
            $status,
            $sortOrder,
            $effectiveAt,
            $expiresAt,
            $expectedRevision,
        ): EffectiveReferenceCode {
            $comparisonTime = $this->repository->replace(
                $definition,
                $context,
                $code,
                $label,
                $metadataJson,
                $status,
                $sortOrder,
                $effectiveAt,
                $expiresAt,
                $expectedRevision,
            );

            return (new ReferenceCodeQuery($this->repository))->get(
                $definition,
                $context,
                $code,
                $comparisonTime,
            );
        });
    }

    public function retire(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code,
        ?string $ifMatch,
    ): EffectiveReferenceCode {
        $expectedRevision = $this->strongRevision($ifMatch);
        $code = $this->code($code);

        return $this->repository->atomically(function () use (
            $definition,
            $context,
            $code,
            $expectedRevision,
        ): EffectiveReferenceCode {
            $comparisonTime = $this->repository->retire(
                $definition,
                $context,
                $code,
                $expectedRevision,
            );

            return (new ReferenceCodeQuery($this->repository))->get(
                $definition,
                $context,
                $code,
                $comparisonTime,
            );
        });
    }

    private function assertCreatePrecondition(?string $ifNoneMatch): void
    {
        if ($ifNoneMatch !== '*') {
            throw ReferenceCodeException::preconditionRequired();
        }
    }

    private function strongRevision(?string $etag): int
    {
        if (!is_string($etag) || preg_match('/^"rev-([1-9][0-9]*)"$/D', $etag, $matches) !== 1) {
            throw ReferenceCodeException::preconditionRequired();
        }
        if (strlen($matches[1]) > strlen((string) PHP_INT_MAX)
            || (strlen($matches[1]) === strlen((string) PHP_INT_MAX) && strcmp($matches[1], (string) PHP_INT_MAX) > 0)) {
            throw ReferenceCodeException::preconditionRequired();
        }

        return (int) $matches[1];
    }

    private function code(string $code): string
    {
        if (strlen($code) > 64 || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $code) !== 1) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_REQUEST_INVALID',
                'The reference-code identity is invalid.',
            );
        }

        return $code;
    }

    /**
     * @param array<array-key, mixed> $metadata
     * @return array{string, array<string, null|bool|int|float|string>, string}
     */
    private function versionInput(
        string $label,
        array $metadata,
        string $status,
        int $sortOrder,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
    ): array {
        $label = trim($label);
        if (!$this->validUtf8Length($label, 160)) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_REQUEST_INVALID',
                'The reference-code label is invalid.',
            );
        }
        if (!in_array($status, ['active', 'inactive'], true)
            || $sortOrder < -1000000
            || $sortOrder > 1000000) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_REQUEST_INVALID',
                'The reference-code version input is invalid.',
            );
        }
        $this->assertInterval($effectiveAt, $expiresAt);
        [$metadata, $metadataJson] = $this->metadata($metadata);

        return [$label, $metadata, $metadataJson];
    }

    /** @param array<array-key, mixed> $metadata
     * @return array{array<string, null|bool|int|float|string>, string}
     */
    private function metadata(array $metadata): array
    {
        if ((array_is_list($metadata) && $metadata !== []) || count($metadata) > 32) {
            throw $this->metadataInvalid();
        }
        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key)
                || strlen($key) > 64
                || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $key) !== 1) {
                throw $this->metadataInvalid();
            }
            if (is_string($value)) {
                if (!$this->validUtf8Length($value, 500, true)) {
                    throw $this->metadataInvalid();
                }
            } elseif (is_float($value)) {
                if (!is_finite($value)) {
                    throw $this->metadataInvalid();
                }
            } elseif ($value !== null && !is_bool($value) && !is_int($value)) {
                throw $this->metadataInvalid();
            }
            $normalized[$key] = $value;
        }
        ksort($normalized, SORT_STRING);
        try {
            $json = $normalized === []
                ? '{}'
                : json_encode(
                    $normalized,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
                );
        } catch (\JsonException) {
            throw $this->metadataInvalid();
        }
        if (strlen($json) > 8192) {
            throw $this->metadataInvalid();
        }

        return [$normalized, $json];
    }

    private function assertInterval(DateTimeImmutable $effectiveAt, ?DateTimeImmutable $expiresAt): void
    {
        if (((int) $effectiveAt->format('u')) % 1000 !== 0
            || ($expiresAt !== null && ((int) $expiresAt->format('u')) % 1000 !== 0)
            || ($expiresAt !== null && $expiresAt <= $effectiveAt)) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_INTERVAL_INVALID',
                'Reference-code timestamps require exact milliseconds and a non-empty interval.',
            );
        }
    }

    private function validUtf8Length(string $value, int $maximum, bool $allowEmpty = false): bool
    {
        if (preg_match('//u', $value) !== 1 || (!$allowEmpty && $value === '')) {
            return false;
        }
        $count = preg_match_all('/./us', $value, $matches);

        return is_int($count) && $count <= $maximum;
    }

    private function metadataInvalid(): ReferenceCodeException
    {
        return ReferenceCodeException::invalid(
            'REFERENCE_CODE_METADATA_INVALID',
            'Reference-code metadata must be a bounded scalar JSON object.',
        );
    }
}
