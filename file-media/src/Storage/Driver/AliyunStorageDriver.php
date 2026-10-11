<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage\Driver;

use OSS\OssClient;
use PeanutAdmin\FileMedia\Storage\StorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageObjectKey;
use PeanutAdmin\FileMedia\Storage\StorageDriverCapabilities;
use PeanutAdmin\FileMedia\Storage\StorageCapabilityUnsupported;
use PeanutAdmin\FileMedia\Storage\StorageObjectObservation;
use PeanutAdmin\FileMedia\Storage\StorageOperationReceipt;
use PeanutAdmin\FileMedia\Storage\StorageVersionCursor;
use PeanutAdmin\FileMedia\Storage\StorageVersionPage;

/** Low-level Aliyun OSS driver using a Host-assembled SDK client. */
final readonly class AliyunStorageDriver implements StorageDriver
{
    public function __construct(
        private OssClient $client,
        private string $bucket,
    ) {}

    public function capabilities(): StorageDriverCapabilities
    {
        return new StorageDriverCapabilities();
    }

    public function inspect(string $objectKey, ?string $versionId = null): StorageObjectObservation
    {
        StorageObjectKey::assert($objectKey);
        throw new StorageCapabilityUnsupported('object-inspection');
    }

    public function restrictToPrivate(string $objectKey, ?string $versionId = null): StorageOperationReceipt
    {
        StorageObjectKey::assert($objectKey);
        throw new StorageCapabilityUnsupported('object-acl-restriction');
    }

    public function listVersions(string $objectKey, ?StorageVersionCursor $cursor = null, int $limit = 100): StorageVersionPage
    {
        StorageObjectKey::assert($objectKey);
        throw new StorageCapabilityUnsupported('object-version-listing');
    }

    /** @inheritDoc */
    public function put(string $objectKey, string $sourcePath): void
    {
        $this->client->uploadFile(
            $this->bucket,
            StorageObjectKey::assert($objectKey),
            $sourcePath,
            [OssClient::OSS_HEADERS => [OssClient::OSS_OBJECT_ACL => OssClient::OSS_ACL_TYPE_PRIVATE]],
        );
    }

    /** @inheritDoc */
    public function delete(string $objectKey, ?string $versionId = null): StorageOperationReceipt
    {
        $objectKey = StorageObjectKey::assert($objectKey);
        $this->assertUnversioned($versionId);
        $result = $this->client->deleteObject($this->bucket, $objectKey);
        // The locked OSS SDK returns HTTP headers for deleteObject.
        if (!is_array($result) || !isset($result['x-oss-request-id']) || !is_string($result['x-oss-request-id']) || $result['x-oss-request-id'] === '') {
            throw new \UnexpectedValueException('STORAGE_DELETE_ACKNOWLEDGEMENT_UNKNOWN');
        }
        return new StorageOperationReceipt('delete', 'acknowledged', $result['x-oss-request-id']);
    }

    /** @inheritDoc */
    public function downloadTo(string $objectKey, string $targetPath, ?string $versionId = null): void
    {
        StorageObjectKey::assert($objectKey);
        $this->assertUnversioned($versionId);
        $this->client->getObject(
            $this->bucket,
            StorageObjectKey::assert($objectKey),
            [OssClient::OSS_FILE_DOWNLOAD => $targetPath],
        );
    }

    private function assertUnversioned(?string $versionId): void
    {
        if ($versionId !== null) {
            throw new StorageCapabilityUnsupported('object-version');
        }
    }

    /** @inheritDoc */
    public function localPath(string $objectKey): ?string
    {
        return null;
    }
}
