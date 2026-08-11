<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Model;

use JsonException;
use UnexpectedValueException;

final readonly class ArtifactRevision
{
    /** @var list<string> */
    private const ENVELOPE_KEYS = [
        'artifact_type',
        'artifact_key',
        'revision_key',
        'revision_number',
        'parent_revision_key',
        'payload_schema_key',
        'payload_schema_version',
        'payload_ref',
        'payload_sha256',
        'attachment_manifest_sha256',
    ];

    public function __construct(
        public int $id,
        public int $tenantId,
        public int $artifactId,
        public string $artifactType,
        public string $artifactKey,
        public string $revisionKey,
        public int $revisionNumber,
        public ?int $parentRevisionId,
        public ?string $parentRevisionKey,
        public string $state,
        public int $revision,
        public ?string $payloadSchemaKey,
        public ?string $payloadSchemaVersion,
        public ?string $payloadRef,
        public ?string $payloadSha256,
        public ?string $attachmentManifestSha256,
        public ?string $canonicalEnvelopeJson,
        public ?string $canonicalEnvelopeSha256,
        public int $createdByMemberId,
        public ?int $finalizedByMemberId,
        public string $createdAt,
        public ?string $finalizedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['artifact_id'],
            (string) ($row['artifact_type'] ?? ''),
            (string) ($row['artifact_key'] ?? ''),
            (string) $row['revision_key'],
            (int) $row['revision_number'],
            $row['parent_revision_id'] === null ? null : (int) $row['parent_revision_id'],
            !array_key_exists('parent_revision_key', $row) || $row['parent_revision_key'] === null
                ? null
                : (string) $row['parent_revision_key'],
            (string) $row['state'],
            (int) $row['revision'],
            $row['payload_schema_key'] === null ? null : (string) $row['payload_schema_key'],
            $row['payload_schema_version'] === null ? null : (string) $row['payload_schema_version'],
            $row['payload_ref'] === null ? null : (string) $row['payload_ref'],
            $row['payload_sha256'] === null ? null : (string) $row['payload_sha256'],
            $row['attachment_manifest_sha256'] === null
                ? null
                : (string) $row['attachment_manifest_sha256'],
            $row['canonical_envelope_json'] === null ? null : (string) $row['canonical_envelope_json'],
            $row['canonical_envelope_sha256'] === null
                ? null
                : (string) $row['canonical_envelope_sha256'],
            (int) $row['created_by_member_id'],
            $row['finalized_by_member_id'] === null ? null : (int) $row['finalized_by_member_id'],
            (string) $row['created_at'],
            $row['finalized_at'] === null ? null : (string) $row['finalized_at'],
        );
    }

    public function isFinalized(): bool
    {
        return $this->state === 'finalized';
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'artifact_id' => $this->artifactId,
            'artifact_type' => $this->artifactType,
            'artifact_key' => $this->artifactKey,
            'revision_key' => $this->revisionKey,
            'revision_number' => $this->revisionNumber,
            'parent_revision_id' => $this->parentRevisionId,
            'parent_revision_key' => $this->parentRevisionKey,
            'state' => $this->state,
            'revision' => $this->revision,
            'payload_schema_key' => $this->payloadSchemaKey,
            'payload_schema_version' => $this->payloadSchemaVersion,
            'payload_ref' => $this->payloadRef,
            'payload_sha256' => $this->payloadSha256,
            'attachment_manifest_sha256' => $this->attachmentManifestSha256,
            'canonical_envelope_json' => $this->canonicalEnvelopeJson,
            'canonical_envelope_sha256' => $this->canonicalEnvelopeSha256,
            'created_by_member_id' => $this->createdByMemberId,
            'finalized_by_member_id' => $this->finalizedByMemberId,
            'created_at' => $this->createdAt,
            'finalized_at' => $this->finalizedAt,
        ];
    }

    /** @return array<string, int|string|null> */
    public function expectedEnvelope(): array
    {
        return [
            'artifact_type' => $this->artifactType,
            'artifact_key' => $this->artifactKey,
            'revision_key' => $this->revisionKey,
            'revision_number' => $this->revisionNumber,
            'parent_revision_key' => $this->parentRevisionKey,
            'payload_schema_key' => $this->payloadSchemaKey,
            'payload_schema_version' => $this->payloadSchemaVersion,
            'payload_ref' => $this->payloadRef,
            'payload_sha256' => $this->payloadSha256,
            'attachment_manifest_sha256' => $this->attachmentManifestSha256,
        ];
    }

    /** @param array<string, int|string|null> $envelope */
    public static function encodeEnvelope(array $envelope): string
    {
        if (array_keys($envelope) !== self::ENVELOPE_KEYS) {
            throw new UnexpectedValueException('Artifact revision envelope fields are not canonical.');
        }

        try {
            return json_encode(
                $envelope,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Artifact revision envelope cannot be encoded.', 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    public function canonicalEnvelope(): array
    {
        if (!$this->isFinalized() || $this->canonicalEnvelopeJson === null) {
            throw new UnexpectedValueException('A pending artifact revision has no canonical envelope.');
        }
        try {
            $decoded = json_decode($this->canonicalEnvelopeJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Artifact revision envelope JSON is invalid.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded) || !$this->hasExactEnvelopeKeys($decoded)) {
            throw new UnexpectedValueException('Artifact revision envelope fields are invalid.');
        }

        return $decoded;
    }

    /**
     * Verify that a finalized row still contains the exact immutable envelope
     * represented by its columns. Pending rows deliberately have no envelope.
     */
    public function assertEnvelopeIntegrity(): void
    {
        if (!$this->isFinalized()) {
            return;
        }
        $envelope = $this->canonicalEnvelope();
        $expected = $this->expectedEnvelope();
        foreach (self::ENVELOPE_KEYS as $key) {
            if (!array_key_exists($key, $envelope) || $envelope[$key] !== $expected[$key]) {
                throw new UnexpectedValueException('Artifact revision envelope does not match its columns.');
            }
        }
        $canonical = self::encodeEnvelope($expected);
        if ($this->canonicalEnvelopeSha256 === null
            || !hash_equals($this->canonicalEnvelopeSha256, hash('sha256', $canonical))) {
            throw new UnexpectedValueException('Artifact revision envelope digest is invalid.');
        }
    }

    /** @param array<mixed> $decoded */
    private function hasExactEnvelopeKeys(array $decoded): bool
    {
        return count($decoded) === count(self::ENVELOPE_KEYS)
            && array_diff(self::ENVELOPE_KEYS, array_keys($decoded)) === []
            && array_diff(array_keys($decoded), self::ENVELOPE_KEYS) === [];
    }
}
