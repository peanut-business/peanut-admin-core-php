<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Application;

use PeanutAdmin\ArtifactRevision\Package;

final readonly class ArtifactRevisionReceipt
{
    public function __construct(
        public string $operation,
        public string $artifactType,
        public string $artifactKey,
        public int $artifactRevision,
        public string $revisionKey,
        public int $revisionNumber,
        public ?string $parentRevisionKey,
        public string $state,
        public int $revision,
        public ?string $canonicalEnvelopeSha256,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'artifact_type' => $this->artifactType,
            'artifact_key' => $this->artifactKey,
            'artifact_revision' => $this->artifactRevision,
            'revision_key' => $this->revisionKey,
            'revision_number' => $this->revisionNumber,
            'parent_revision_key' => $this->parentRevisionKey,
            'state' => $this->state,
            'revision' => $this->revision,
            'canonical_envelope_sha256' => $this->canonicalEnvelopeSha256,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value, string $expectedOperation): self
    {
        $expectedKeys = [
            'operation',
            'artifact_type',
            'artifact_key',
            'artifact_revision',
            'revision_key',
            'revision_number',
            'parent_revision_key',
            'state',
            'revision',
            'canonical_envelope_sha256',
        ];
        $actualKeys = array_keys($value);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys
            || !in_array($expectedOperation, [Package::CREATE_OPERATION, Package::FINALIZE_OPERATION], true)
            || !is_string($value['operation'])
            || !hash_equals($expectedOperation, $value['operation'])
            || !is_string($value['artifact_type'])
            || !is_string($value['artifact_key'])
            || !is_int($value['artifact_revision'])
            || $value['artifact_revision'] < 1
            || !is_string($value['revision_key'])
            || preg_match('/^revision_[0-9a-f]{32}$/D', $value['revision_key']) !== 1
            || !is_int($value['revision_number'])
            || $value['revision_number'] < 1
            || ($value['parent_revision_key'] !== null
                && (!is_string($value['parent_revision_key'])
                    || preg_match('/^revision_[0-9a-f]{32}$/D', $value['parent_revision_key']) !== 1))
            || !is_string($value['state'])
            || !in_array($value['state'], ['pending', 'finalized'], true)
            || !is_int($value['revision'])
            || $value['revision'] < 1
            || ($value['canonical_envelope_sha256'] !== null
                && (!is_string($value['canonical_envelope_sha256'])
                    || preg_match('/^[0-9a-f]{64}$/D', $value['canonical_envelope_sha256']) !== 1))
        ) {
            throw ArtifactRevisionException::internal();
        }
        if (($value['operation'] === Package::CREATE_OPERATION
                && ($value['state'] !== 'pending' || $value['canonical_envelope_sha256'] !== null))
            || ($value['operation'] === Package::FINALIZE_OPERATION
                && ($value['state'] !== 'finalized' || $value['canonical_envelope_sha256'] === null))) {
            throw ArtifactRevisionException::internal();
        }

        return new self(
            $value['operation'],
            $value['artifact_type'],
            $value['artifact_key'],
            $value['artifact_revision'],
            $value['revision_key'],
            $value['revision_number'],
            $value['parent_revision_key'],
            $value['state'],
            $value['revision'],
            $value['canonical_envelope_sha256'],
        );
    }
}
