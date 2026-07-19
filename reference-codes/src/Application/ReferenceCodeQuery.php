<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Application;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetDefinition;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetRegistry;
use PeanutAdmin\ReferenceCodes\Persistence\PdoReferenceCodeRepository;

final readonly class ReferenceCodeQuery
{
    public function __construct(private PdoReferenceCodeRepository $repository) {}

    /** @return list<array{module_key: string, set_key: string, name: string, description: string, definition_revision: int}> */
    public function sets(ReferenceCodeSetRegistry $registry): array
    {
        return $this->repository->definitionSummaries($registry);
    }

    public function get(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code,
        ?DateTimeImmutable $asOf = null,
    ): EffectiveReferenceCode {
        $this->assertCode($code);
        $snapshot = $this->repository->snapshot($definition, $context, $code, $asOf);
        if (count($snapshot['entries']) !== 1) {
            throw ReferenceCodeException::codeNotFound();
        }
        $entry = $this->hydrate($definition, $snapshot['entries'][0], $snapshot['as_of']);
        if ($entry === null) {
            throw ReferenceCodeException::codeNotFound();
        }

        return $entry;
    }

    /**
     * @return array{
     *   items: list<EffectiveReferenceCode>,
     *   as_of: string,
     *   page: int,
     *   page_size: int,
     *   total: int
     * }
     */
    public function list(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        ?DateTimeImmutable $asOf = null,
        string $effectiveStatus = 'all',
        bool $includeRetired = false,
        int $page = 1,
        int $pageSize = 50,
    ): array {
        if (!in_array($effectiveStatus, ['active', 'inactive', 'all'], true)
            || $page < 1
            || $page > 10000
            || $pageSize < 1
            || $pageSize > 100) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_REQUEST_INVALID',
                'The reference-code query is invalid.',
            );
        }
        $snapshot = $this->repository->snapshot($definition, $context, null, $asOf);
        $items = [];
        foreach ($snapshot['entries'] as $raw) {
            $entry = $this->hydrate($definition, $raw, $snapshot['as_of']);
            if ($entry === null || (!$includeRetired && $entry->lifecycle === 'retired')) {
                continue;
            }
            $status = $entry->effective['status'] ?? null;
            if ($effectiveStatus !== 'all' && $status !== $effectiveStatus) {
                continue;
            }
            $items[] = $entry;
        }
        usort($items, static function (EffectiveReferenceCode $left, EffectiveReferenceCode $right): int {
            if ($left->effective === null) {
                return $right->effective === null ? strcmp($left->code, $right->code) : 1;
            }
            if ($right->effective === null) {
                return -1;
            }
            $sort = $left->effective['sort_order'] <=> $right->effective['sort_order'];

            return $sort !== 0 ? $sort : strcmp($left->code, $right->code);
        });
        $total = count($items);
        $items = array_slice($items, ($page - 1) * $pageSize, $pageSize);

        return [
            'items' => $items,
            'as_of' => $this->rfc3339($snapshot['as_of']),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ];
    }

    public function resolve(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code,
        ?DateTimeImmutable $asOf = null,
    ): ?EffectiveReferenceCode {
        try {
            $entry = $this->get($definition, $context, $code, $asOf);
        } catch (ReferenceCodeException $exception) {
            if ($exception->errorCode === 'REFERENCE_CODE_NOT_FOUND') {
                return null;
            }
            throw $exception;
        }

        return $entry->selectable() ? $entry : null;
    }

    /** @return list<EffectiveReferenceCode> */
    public function listActiveCandidates(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        ?DateTimeImmutable $asOf = null,
        int $page = 1,
        int $pageSize = 100,
    ): array {
        return $this->list(
            $definition,
            $context,
            $asOf,
            'active',
            false,
            $page,
            $pageSize,
        )['items'];
    }

    /**
     * @param array{entry: array<string, mixed>, versions: list<array<string, mixed>>} $raw
     */
    private function hydrate(
        ReferenceCodeSetDefinition $definition,
        array $raw,
        DateTimeImmutable $asOf,
    ): ?EffectiveReferenceCode {
        $entry = $raw['entry'];
        $revision = $this->positiveInteger($entry['revision'] ?? null);
        $code = $entry['code'] ?? null;
        $lifecycle = $entry['lifecycle'] ?? null;
        if (!is_string($code)
            || strlen($code) > 64
            || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $code) !== 1
            || !in_array($lifecycle, ['active', 'retired'], true)) {
            throw ReferenceCodeException::internal();
        }
        $createdAt = $this->databaseDate($entry['created_at'] ?? null);
        $updatedAt = $this->databaseDate($entry['updated_at'] ?? null);
        $retiredAt = ($entry['retired_at'] ?? null) === null
            ? null
            : $this->databaseDate($entry['retired_at']);
        if (($lifecycle === 'active') !== ($retiredAt === null)
            || $updatedAt < $createdAt
            || $asOf < $createdAt
            || count($raw['versions']) !== $revision) {
            if ($asOf < $createdAt && count($raw['versions']) === $revision) {
                return null;
            }
            throw ReferenceCodeException::internal();
        }

        $versions = [];
        foreach ($raw['versions'] as $index => $version) {
            $versionRevision = $this->positiveInteger($version['revision'] ?? null);
            if ($versionRevision !== $index + 1) {
                throw ReferenceCodeException::internal();
            }
            $label = $version['label'] ?? null;
            $status = $version['status'] ?? null;
            $sortOrder = $this->integer($version['sort_order'] ?? null);
            if (!is_string($label)
                || trim($label) !== $label
                || !$this->validUtf8Length($label, 160)
                || !in_array($status, ['active', 'inactive'], true)
                || $sortOrder < -1000000
                || $sortOrder > 1000000) {
                throw ReferenceCodeException::internal();
            }
            $effectiveAt = $this->databaseDate($version['effective_at'] ?? null);
            $expiresAt = ($version['expires_at'] ?? null) === null
                ? null
                : $this->databaseDate($version['expires_at']);
            if ($expiresAt !== null && $expiresAt <= $effectiveAt) {
                throw ReferenceCodeException::internal();
            }
            $versions[] = [
                'revision' => $versionRevision,
                'label' => $label,
                'metadata' => $this->metadata($version['metadata_json'] ?? null),
                'status' => $status,
                'sort_order' => $sortOrder,
                'effective_at_value' => $effectiveAt,
                'effective_at' => $this->rfc3339($effectiveAt),
                'expires_at_value' => $expiresAt,
                'expires_at' => $expiresAt === null ? null : $this->rfc3339($expiresAt),
            ];
        }
        if ($lifecycle === 'retired') {
            $terminal = $versions[$revision - 1] ?? null;
            if ($terminal === null
                || $retiredAt === null
                || $terminal['status'] !== 'inactive'
                || $terminal['effective_at_value'] != $retiredAt
                || $terminal['expires_at_value'] !== null) {
                throw ReferenceCodeException::internal();
            }
        }

        $winner = null;
        foreach ($versions as $version) {
            if ($version['effective_at_value'] <= $asOf
                && ($version['expires_at_value'] === null || $asOf < $version['expires_at_value'])) {
                $winner = $version;
            }
        }
        if ($winner !== null) {
            unset($winner['effective_at_value'], $winner['expires_at_value']);
        }
        $latest = $versions[$revision - 1];
        unset($latest['effective_at_value'], $latest['expires_at_value']);
        $asOfLifecycle = $retiredAt !== null && $retiredAt <= $asOf ? 'retired' : 'active';

        return new EffectiveReferenceCode(
            $definition->moduleKey,
            $definition->key,
            $code,
            $asOfLifecycle,
            $revision,
            '"rev-' . $revision . '"',
            $winner,
            $this->rfc3339($createdAt),
            $this->rfc3339($updatedAt),
            $retiredAt === null ? null : $this->rfc3339($retiredAt),
            $this->rfc3339($asOf),
            $latest,
        );
    }

    /** @return array<string, null|bool|int|float|string> */
    private function metadata(mixed $json): array
    {
        if (!is_string($json) || strlen($json) > 8192 || !str_starts_with(ltrim($json), '{')) {
            throw ReferenceCodeException::internal();
        }
        try {
            $metadata = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ReferenceCodeException::internal();
        }
        if (!is_array($metadata) || (array_is_list($metadata) && $metadata !== []) || count($metadata) > 32) {
            throw ReferenceCodeException::internal();
        }
        foreach ($metadata as $key => $value) {
            if (!is_string($key)
                || strlen($key) > 64
                || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $key) !== 1) {
                throw ReferenceCodeException::internal();
            }
            if (is_string($value)) {
                if (!$this->validUtf8Length($value, 500, true)) {
                    throw ReferenceCodeException::internal();
                }
            } elseif (is_float($value)) {
                if (!is_finite($value)) {
                    throw ReferenceCodeException::internal();
                }
            } elseif ($value !== null && !is_bool($value) && !is_int($value)) {
                throw ReferenceCodeException::internal();
            }
        }
        ksort($metadata, SORT_STRING);

        return $metadata;
    }

    private function assertCode(string $code): void
    {
        if (strlen($code) > 64 || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $code) !== 1) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_REQUEST_INVALID',
                'The reference-code identity is invalid.',
            );
        }
    }

    private function databaseDate(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}$/D', $value) !== 1) {
            throw ReferenceCodeException::internal();
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s.v') !== $value) {
            throw ReferenceCodeException::internal();
        }

        return $date;
    }

    private function positiveInteger(mixed $value): int
    {
        $integer = $this->integer($value);
        if ($integer < 1) {
            throw ReferenceCodeException::internal();
        }

        return $integer;
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw ReferenceCodeException::internal();
        }
        $negative = str_starts_with($value, '-');
        $digits = $negative ? substr($value, 1) : $value;
        $limit = $negative ? ltrim((string) PHP_INT_MIN, '-') : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw ReferenceCodeException::internal();
        }

        return (int) $value;
    }

    private function validUtf8Length(string $value, int $maximum, bool $allowEmpty = false): bool
    {
        if (preg_match('//u', $value) !== 1 || (!$allowEmpty && $value === '')) {
            return false;
        }
        $count = preg_match_all('/./us', $value, $matches);

        return is_int($count) && $count <= $maximum;
    }

    private function rfc3339(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
