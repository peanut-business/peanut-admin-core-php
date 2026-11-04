<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage\Driver;

use PeanutAdmin\FileMedia\Storage\StorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageHttpTransport;
use PeanutAdmin\FileMedia\Storage\StorageObjectKey;
use PeanutAdmin\FileMedia\Storage\StorageDriverCapabilities;
use PeanutAdmin\FileMedia\Storage\StorageCapabilityUnsupported;
use PeanutAdmin\FileMedia\Storage\StorageObjectObservation;
use PeanutAdmin\FileMedia\Storage\StorageOperationReceipt;
use PeanutAdmin\FileMedia\Storage\StorageVersionCursor;
use PeanutAdmin\FileMedia\Storage\StorageVersionPage;
use Qiniu\Auth;

/** Low-level Qiniu driver using Host-owned authentication and HTTP transport. */
final readonly class QiniuStorageDriver implements StorageDriver
{
    private string $uploadEndpoint;

    /** Normalizes the endpoint while preserving Qiniu's existing global default. */
    public function __construct(
        private Auth $auth,
        private string $bucket,
        string $uploadEndpoint,
        private string $downloadDomain,
        private StorageHttpTransport $transport,
    ) {
        $uploadEndpoint = rtrim(trim($uploadEndpoint), '/');
        // Preserve Qiniu's global upload endpoint when the Host does not override it.
        $this->uploadEndpoint = $uploadEndpoint !== '' ? $uploadEndpoint : 'https://upload.qiniup.com';
    }

    public function capabilities(): StorageDriverCapabilities
    {
        return new StorageDriverCapabilities(objectInspection: true);
    }

    public function inspect(string $objectKey, ?string $versionId = null): StorageObjectObservation
    {
        $objectKey = StorageObjectKey::assert($objectKey);
        $this->assertUnversioned($versionId);
        $url = 'https://rs.qiniuapi.com/stat/' . \Qiniu\entry($this->bucket, $objectKey);
        $response = $this->transport->request([
            'method' => 'GET', 'url' => $url,
            'headers' => $this->auth->authorization($url, null, 'application/x-www-form-urlencoded'),
            'timeout' => 30, 'retrySafe' => true,
        ]);
        $requestId = $this->requestId($response);
        $payload = json_decode($response['body'], true);
        if ($response['status'] === 612 && is_array($payload) && ($payload['error'] ?? null) === 'no such file or directory') {
            return new StorageObjectObservation(false, requestId: $requestId);
        }
        if ($response['status'] !== 200 || !is_array($payload)
            || !isset($payload['fsize'], $payload['mimeType'], $payload['hash'])
            || !is_int($payload['fsize']) || $payload['fsize'] < 0
            || !is_string($payload['mimeType']) || !is_string($payload['hash']) || $payload['hash'] === '') {
            throw new \UnexpectedValueException('STORAGE_OBJECT_OBSERVATION_FAILED');
        }
        return new StorageObjectObservation(
            true, $payload['fsize'], strtolower(trim(explode(';', $payload['mimeType'], 2)[0])),
            false, $payload['hash'], requestId: $requestId,
        );
    }

    public function restrictToPrivate(string $objectKey, ?string $versionId = null): StorageOperationReceipt
    {
        StorageObjectKey::assert($objectKey);
        $this->assertUnversioned($versionId);
        // Kodo visibility belongs to the Bucket, never a per-object ACL mutation.
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
        $objectKey = StorageObjectKey::assert($objectKey);
        $stream = fopen($sourcePath, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('待上传文件不可读');
        }

        try {
            $response = $this->transport->request([
                'method' => 'POST',
                'url' => $this->uploadEndpoint,
                'timeout' => 120,
                'multipart' => [
                    ['name' => 'token', 'contents' => $this->auth->uploadToken($this->bucket)],
                    ['name' => 'key', 'contents' => $objectKey],
                    ['name' => 'file', 'contents' => $stream, 'filename' => basename($objectKey)],
                ],
            ]);
        } finally {
            // The Host transport may consume and close multipart streams.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $payload = json_decode($response['body'], true);
        $returnedKey = is_array($payload) ? trim((string) ($payload['key'] ?? '')) : '';
        if ($response['status'] < 200 || $response['status'] >= 300
            || $returnedKey === '' || !hash_equals($objectKey, $returnedKey)) {
            throw new \RuntimeException('七牛对象上传失败');
        }
    }

    /** @inheritDoc */
    public function delete(string $objectKey, ?string $versionId = null): StorageOperationReceipt
    {
        StorageObjectKey::assert($objectKey);
        $this->assertUnversioned($versionId);
        $entry = \Qiniu\entry(
            $this->bucket,
            $objectKey,
        );
        $url = 'https://rs.qiniuapi.com/delete/' . $entry;
        $response = $this->transport->request([
            'method' => 'POST',
            'url' => $url,
            'headers' => $this->auth->authorization($url, null, 'application/x-www-form-urlencoded'),
            'timeout' => 30,
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('七牛对象删除失败');
        }
        return new StorageOperationReceipt('delete', 'acknowledged', $this->requestId($response));
    }

    /** @inheritDoc */
    public function downloadTo(string $objectKey, string $targetPath, ?string $versionId = null): void
    {
        StorageObjectKey::assert($objectKey);
        $this->assertUnversioned($versionId);
        $url = $this->auth->privateDownloadUrl($this->base($objectKey), 60);
        $response = $this->transport->request([
            'method' => 'GET',
            'url' => $url,
            'retrySafe' => true,
            'sink' => $targetPath,
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('七牛对象下载失败');
        }
    }

    private function assertUnversioned(?string $versionId): void
    {
        if ($versionId !== null) {
            throw new StorageCapabilityUnsupported('object-version');
        }
    }

    /** @param array{status: int, body: string, headers?: array<string, string>} $response */
    private function requestId(array $response): string
    {
        foreach ($response['headers'] ?? [] as $name => $value) {
            if (strtolower($name) === 'x-reqid' && is_string($value) && $value !== ''
                && strlen($value) <= 1024 && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1) {
                return $value;
            }
        }
        throw new \UnexpectedValueException('STORAGE_PROVIDER_RESPONSE_UNKNOWN');
    }

    /** @inheritDoc */
    public function localPath(string $objectKey): ?string
    {
        return null;
    }

    /** Builds the provider download URL without changing the configured domain. */
    private function base(string $objectKey): string
    {
        $domain = rtrim($this->downloadDomain, '/');
        if ($domain === '') {
            throw new \RuntimeException('七牛访问域名未配置');
        }

        return $domain . '/' . StorageObjectKey::assert($objectKey);
    }
}
