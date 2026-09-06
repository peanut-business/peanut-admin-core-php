<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage\Driver;

use PeanutAdmin\FileMedia\Storage\StorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageHttpTransport;
use PeanutAdmin\FileMedia\Storage\StorageObjectKey;
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
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($payload)
            || trim((string) ($payload['key'] ?? '')) === '') {
            throw new \RuntimeException('七牛对象上传失败');
        }
    }

    /** @inheritDoc */
    public function delete(string $objectKey): void
    {
        $entry = \Qiniu\entry(
            $this->bucket,
            StorageObjectKey::assert($objectKey),
        );
        $url = 'https://rs.qiniu.com/delete/' . $entry;
        $response = $this->transport->request([
            'method' => 'POST',
            'url' => $url,
            'headers' => $this->auth->authorization($url, null, 'application/x-www-form-urlencoded'),
            'timeout' => 30,
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('七牛对象删除失败');
        }
    }

    /** @inheritDoc */
    public function downloadTo(string $objectKey, string $targetPath): void
    {
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
