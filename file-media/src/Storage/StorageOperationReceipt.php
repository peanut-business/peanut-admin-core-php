<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

/** Acknowledgement of one technical operation; anonymous denial needs separate verification. */
final readonly class StorageOperationReceipt
{
    public function __construct(
        public string $operation,
        public string $outcome,
        public ?string $requestId = null,
        public ?string $versionId = null,
        public bool $deleteMarker = false,
    ) {
        if (!in_array($operation, ['delete', 'restrict-private'], true)
            || !in_array($outcome, ['acknowledged', 'already-absent'], true)
            || ($operation !== 'delete' && ($deleteMarker || $outcome === 'already-absent'))
        ) {
            throw new \UnexpectedValueException('STORAGE_OPERATION_RECEIPT_INVALID');
        }
        if ($versionId !== null) {
            StorageObjectVersion::assertId($versionId);
        }
    }
}
