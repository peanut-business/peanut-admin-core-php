<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage\Driver;

use OSS\OssClient;
use PeanutAdmin\FileMedia\Storage\StorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageObjectKey;

/** Low-level Aliyun OSS driver using a Host-assembled SDK client. */
final readonly class AliyunStorageDriver implements StorageDriver
{
    public function __construct(
        private OssClient $client,
        private string $bucket,
    ) {}

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
    public function delete(string $objectKey): void
    {
        $this->client->deleteObject($this->bucket, StorageObjectKey::assert($objectKey));
    }

    /** @inheritDoc */
    public function downloadTo(string $objectKey, string $targetPath): void
    {
        $this->client->getObject(
            $this->bucket,
            StorageObjectKey::assert($objectKey),
            [OssClient::OSS_FILE_DOWNLOAD => $targetPath],
        );
    }

    /** @inheritDoc */
    public function localPath(string $objectKey): ?string
    {
        return null;
    }
}
