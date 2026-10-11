<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage\Driver;

use PeanutAdmin\FileMedia\Storage\StorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageObjectKey;
use PeanutAdmin\FileMedia\Storage\StorageDriverCapabilities;
use PeanutAdmin\FileMedia\Storage\StorageCapabilityUnsupported;
use PeanutAdmin\FileMedia\Storage\StorageObjectObservation;
use PeanutAdmin\FileMedia\Storage\StorageObjectVersion;
use PeanutAdmin\FileMedia\Storage\StorageOperationReceipt;
use PeanutAdmin\FileMedia\Storage\StorageVersionCursor;
use PeanutAdmin\FileMedia\Storage\StorageVersionPage;
use Qcloud\Cos\Client;
use Qcloud\Cos\Exception\ServiceResponseException;

/** Low-level Tencent COS driver using a Host-assembled SDK client. */
final readonly class QcloudStorageDriver implements StorageDriver
{
    public function __construct(
        private Client $client,
        private string $bucket,
    ) {}

    public function capabilities(): StorageDriverCapabilities
    {
        return new StorageDriverCapabilities(
            objectInspection: true,
            objectAclRestriction: true,
            objectVersionListing: true,
            objectVersionRead: true,
            objectVersionDelete: true,
        );
    }

    public function inspect(string $objectKey, ?string $versionId = null): StorageObjectObservation
    {
        $request = $this->objectRequest($objectKey, $versionId);
        try {
            $result = $this->client->headObject($request);
        } catch (ServiceResponseException $exception) {
            if ($exception->getStatusCode() !== 404
                || !in_array($exception->getExceptionCode(), ['NoSuchKey', 'NoSuchVersion'], true)) {
                throw $exception;
            }
            return new StorageObjectObservation(false, versionId: $versionId, requestId: $this->text($exception->getRequestId()));
        }
        $requestId = $this->requestId($result);
        $size = $this->size($result['ContentLength'] ?? null);
        $etag = $this->text($result['ETag'] ?? null);
        $mime = strtolower(trim(explode(';', $this->text($result['ContentType'] ?? null), 2)[0]));
        $observedVersion = $result['VersionId'] ?? null;
        if ($observedVersion !== null) {
            $observedVersion = StorageObjectVersion::assertId($this->text($observedVersion));
        }
        if ($versionId !== null && $observedVersion !== $versionId) {
            throw new \UnexpectedValueException('STORAGE_VERSION_OBSERVATION_MISMATCH');
        }
        return new StorageObjectObservation(true, $size, $mime, false, $etag, $observedVersion, $requestId);
    }

    public function restrictToPrivate(string $objectKey, ?string $versionId = null): StorageOperationReceipt
    {
        $request = $this->objectRequest($objectKey, $versionId);
        if ($versionId !== null) {
            // cos-sdk-v5 v2.6.17 PutObjectAcl does not serialize VersionId.
            throw new StorageCapabilityUnsupported('historical-object-acl-restriction');
        }
        $result = $this->client->putObjectAcl($request + ['ACL' => 'private']);
        return new StorageOperationReceipt('restrict-private', 'acknowledged', $this->requestId($result));
    }

    public function listVersions(string $objectKey, ?StorageVersionCursor $cursor = null, int $limit = 100): StorageVersionPage
    {
        $objectKey = StorageObjectKey::assert($objectKey);
        if ($limit < 1 || $limit > 1000 || ($cursor !== null && $cursor->objectKey !== $objectKey)) {
            throw new \InvalidArgumentException('STORAGE_VERSION_QUERY_INVALID');
        }
        $request = ['Bucket' => $this->bucket, 'Prefix' => $objectKey, 'MaxKeys' => $limit];
        if ($cursor !== null) {
            $request += ['KeyMarker' => $objectKey, 'VersionIdMarker' => $cursor->versionIdMarker];
        }
        $result = $this->client->listObjectVersions($request);
        $requestId = $this->requestId($result);
        $truncated = $this->boolean($result['IsTruncated'] ?? null);
        $versions = [];
        $count = 0;
        foreach (['Version' => false, 'DeleteMarkers' => true] as $field => $deleteMarker) {
            $rows = $result[$field] ?? [];
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new \UnexpectedValueException('STORAGE_VERSION_RESPONSE_INVALID');
            }
            foreach ($rows as $row) {
                if (!is_array($row) || ++$count > $limit) {
                    throw new \UnexpectedValueException('STORAGE_VERSION_RESPONSE_INVALID');
                }
                $key = $this->text($row['Key'] ?? null);
                if (!str_starts_with($key, $objectKey)) {
                    throw new \UnexpectedValueException('STORAGE_VERSION_RESPONSE_INVALID');
                }
                if ($key !== $objectKey) {
                    continue;
                }
                $versions[] = new StorageObjectVersion(
                    $this->text($row['VersionId'] ?? null),
                    $this->boolean($row['IsLatest'] ?? null),
                    $deleteMarker,
                    $deleteMarker ? null : $this->size($row['Size'] ?? null),
                    $deleteMarker ? null : $this->text($row['ETag'] ?? null),
                );
            }
        }
        $next = null;
        if ($truncated) {
            $nextKey = $this->text($result['NextKeyMarker'] ?? null);
            if (!str_starts_with($nextKey, $objectKey)) {
                throw new \UnexpectedValueException('STORAGE_VERSION_CURSOR_INVALID');
            }
            // COS orders keys lexically. Once the page ends at a larger key,
            // this exact key is exhausted; similarly prefixed keys are not targets.
            if ($nextKey === $objectKey) {
                $next = new StorageVersionCursor($objectKey, $this->text($result['NextVersionIdMarker'] ?? null));
                if ($cursor !== null && $next->versionIdMarker === $cursor->versionIdMarker) {
                    throw new \UnexpectedValueException('STORAGE_VERSION_CURSOR_STALLED');
                }
            }
        }
        return new StorageVersionPage($versions, $next, $requestId);
    }

    /** @inheritDoc */
    public function put(string $objectKey, string $sourcePath): void
    {
        $objectKey = StorageObjectKey::assert($objectKey);
        $stream = fopen($sourcePath, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('待上传文件不可读');
        }

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'Body' => $stream,
                'ACL' => 'private',
            ]);
        } finally {
            // The SDK may consume and close the source stream before returning.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @inheritDoc */
    public function delete(string $objectKey, ?string $versionId = null): StorageOperationReceipt
    {
        $result = $this->client->deleteObject($this->objectRequest($objectKey, $versionId));
        $requestId = $this->requestId($result);
        $returnedVersion = $result['VersionId'] ?? null;
        if ($returnedVersion !== null) {
            $returnedVersion = StorageObjectVersion::assertId($this->text($returnedVersion));
        }
        if ($versionId !== null && $returnedVersion !== $versionId) {
            throw new \UnexpectedValueException('STORAGE_DELETE_VERSION_ACKNOWLEDGEMENT_UNKNOWN');
        }
        return new StorageOperationReceipt(
            'delete', 'acknowledged', $requestId, $returnedVersion,
            isset($result['DeleteMarker']) ? $this->boolean($result['DeleteMarker']) : false,
        );
    }

    /** @inheritDoc */
    public function downloadTo(string $objectKey, string $targetPath, ?string $versionId = null): void
    {
        $request = $this->objectRequest($objectKey, $versionId);
        $this->client->download(
            $this->bucket,
            $request['Key'],
            $targetPath,
            $versionId === null ? [] : ['VersionId' => $versionId],
        );
    }

    /** @inheritDoc */
    public function localPath(string $objectKey): ?string
    {
        return null;
    }

    /** @return array{Bucket: string, Key: string, VersionId?: string} */
    private function objectRequest(string $objectKey, ?string $versionId): array
    {
        $request = ['Bucket' => $this->bucket, 'Key' => StorageObjectKey::assert($objectKey)];
        if ($versionId !== null) {
            $request['VersionId'] = StorageObjectVersion::assertId($versionId);
        }
        return $request;
    }

    private function requestId(mixed $result): string
    {
        if (!is_array($result) && !$result instanceof \ArrayAccess) {
            throw new \UnexpectedValueException('STORAGE_PROVIDER_RESPONSE_UNKNOWN');
        }
        return $this->text($result['RequestId'] ?? null);
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \UnexpectedValueException('STORAGE_PROVIDER_RESPONSE_UNKNOWN');
        }
        return $value;
    }

    private function size(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1
            && (strlen($value) < strlen((string) PHP_INT_MAX) || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) <= 0))) {
            return (int) $value;
        }
        throw new \UnexpectedValueException('STORAGE_PROVIDER_SIZE_UNKNOWN');
    }

    private function boolean(mixed $value): bool
    {
        return match ($value) {
            true, 'true', 1 => true,
            false, 'false', 0 => false,
            default => throw new \UnexpectedValueException('STORAGE_PROVIDER_RESPONSE_UNKNOWN'),
        };
    }
}
