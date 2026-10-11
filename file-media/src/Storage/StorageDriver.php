<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

/** Low-level object storage operations assembled by the consuming Host. */
interface StorageDriver
{
    /** Reports implemented primitives, never effective anonymous-access policy. */
    public function capabilities(): StorageDriverCapabilities;

    /** Observes one exact object/version; provider MIME is not a content verdict. */
    public function inspect(string $objectKey, ?string $versionId = null): StorageObjectObservation;

    /** Applies only the private object ACL; does not close Bucket Policy or CDN access. */
    public function restrictToPrivate(string $objectKey, ?string $versionId = null): StorageOperationReceipt;

    /** Enumerates a bounded page for this exact key, including deletion markers. */
    public function listVersions(string $objectKey, ?StorageVersionCursor $cursor = null, int $limit = 100): StorageVersionPage;

    /** Stores a source file under a validated provider object key. */
    public function put(string $objectKey, string $sourcePath): void;

    /** Returns a provider acknowledgement, not proof that all old bytes are inaccessible. */
    public function delete(string $objectKey, ?string $versionId = null): StorageOperationReceipt;

    /** Downloads the provider object into a Host-owned target path. */
    public function downloadTo(string $objectKey, string $targetPath, ?string $versionId = null): void;

    /** Returns a directly readable path only for local storage. */
    public function localPath(string $objectKey): ?string;
}
