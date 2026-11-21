<?php

namespace OSS {
    class OssClient
    {
        public const OSS_ACL_TYPE_PRIVATE = 'private';
        public const OSS_HEADERS = 'headers';
        public const OSS_OBJECT_ACL = 'x-oss-object-acl';
        public const OSS_FILE_DOWNLOAD = 'fileDownload';

        public function __construct(string $accessKeyId, string $accessKeySecret, string $endpoint, bool $isCName = false, ?string $securityToken = null, ?string $requestProxy = null) {}
        /** @param array<string, mixed>|null $options */
        public function putObject(string $bucket, string $object, string $content, ?array $options = null): mixed {}
        /** @param array<string, mixed>|null $options */
        public function uploadFile(string $bucket, string $object, string $file, ?array $options = null): mixed {}
        /** @param array<string, mixed>|null $options */
        public function deleteObject(string $bucket, string $object, ?array $options = null): mixed {}
        /** @param array<string, mixed>|null $options */
        public function doesObjectExist(string $bucket, string $object, ?array $options = null): bool {}
        /** @param array<string, mixed>|null $options */
        public function signUrl(string $bucket, string $object, int $timeout = 60, string $method = 'GET', ?array $options = null): string {}
        /** @param array<string, mixed>|null $options */
        public function getObject(string $bucket, string $object, ?array $options = null): string {}
        /**
         * @param array<string, mixed>|null $options
         * @return array<string, mixed>
         */
        public function getObjectMeta(string $bucket, string $object, ?array $options = null): array {}
        /** @param array<string, mixed>|null $options */
        public function listObjects(string $bucket, ?array $options = null): mixed {}
    }
}

namespace OSS\Core {
    class OssException extends \Exception
    {
        public function getHTTPStatus(): int|string { return 0; }
        public function getErrorCode(): string { return ''; }
    }
}

namespace Qcloud\Cos {
    class Client
    {
        /** @param array<string, mixed> $config */
        public function __construct(array $config) {}
        /** @param array<string, mixed> $args */
        public function putObject(array $args): mixed {}
        /** @param array<string, mixed> $options */
        public function upload(string $bucket, string $key, mixed $body, array $options = []): mixed {}
        /** @param array<string, mixed> $args */
        public function deleteObject(array $args): mixed {}
        /** @param array<string, mixed> $options */
        public function doesObjectExist(string $bucket, string $key, array $options = []): bool {}
        /** @param array<string, mixed> $args */
        public function getObjectUrl(string $bucket, string $key, ?string $expires = null, array $args = []): string {}
        /** @param array<string, mixed> $args */
        public function getObject(array $args): mixed {}
        /** @param array<string, mixed> $args */
        public function headObject(array $args): mixed {}
        /** @param array<string, mixed> $args */
        public function listObjects(array $args): mixed {}
        /** @param array<string, mixed> $args */
        public function putObjectAcl(array $args): mixed {}
        /** @param array<string, mixed> $args */
        public function listObjectVersions(array $args): mixed {}
        /** @param array<string, mixed> $options */
        public function download(string $bucket, string $key, string $saveAs, array $options = []): mixed {}
    }
}

namespace Qcloud\Cos\Exception {
    class ServiceResponseException extends \RuntimeException
    {
        public function getStatusCode(): int { return 0; }
        public function getExceptionCode(): string { return ''; }
        public function getRequestId(): string { return ''; }
    }
}

namespace Qiniu {
    function entry(string $bucket, ?string $key): string { return ''; }

    class Auth
    {
        public function __construct(string $accessKey, string $secretKey) {}
        /** @param array<string, mixed>|null $policy */
        public function uploadToken(string $bucket, ?string $key = null, int $expires = 3600, ?array $policy = null, bool $strictPolicy = true): string {}
        public function privateDownloadUrl(string $baseUrl, int $expires = 3600): string {}
        /** @return array<string, string> */
        public function authorization(string $url, ?string $body = null, ?string $contentType = null): array { return []; }
    }
}

namespace Qiniu\Storage {
    class UploadManager
    {
        /**
         * @param array<string, mixed>|null $params
         * @return array{0: mixed, 1: mixed}
         */
        public function put(string $upToken, ?string $key, string $data, ?array $params = null, string $mime = 'application/octet-stream', ?string $fname = null): array {}
        /**
         * @param array<string, mixed>|null $params
         * @return array{0: mixed, 1: mixed}
         */
        public function putFile(string $upToken, ?string $key, string $filePath, ?array $params = null, string $mime = 'application/octet-stream', bool $checkCrc = false): array {}
    }

    class BucketManager
    {
        public function __construct(\Qiniu\Auth $auth) {}
        public function delete(string $bucket, string $key): mixed {}
        /** @return array{0: mixed, 1: mixed} */
        public function stat(string $bucket, string $key): array {}
        /** @return array{0: mixed, 1: mixed} */
        public function listFiles(string $bucket, ?string $prefix = null, ?string $marker = null, int $limit = 1000, ?string $delimiter = null): array {}
    }
}

namespace think {
    class Request
    {
        public function middleware(string $name, mixed $default = null): mixed {}
        /** @param array<string, mixed> $middleware */
        public function withMiddleware(array $middleware): static { return $this; }
        public function host(bool $strict = false): string { return ''; }
        public function header(?string $name = null, ?string $default = null): mixed {}
    }
}

namespace think\facade {
    class Cache
    {
        public static function get(string $name, mixed $default = null): mixed {}
        public static function set(string $name, mixed $value, mixed $ttl = null): bool { return true; }
    }
}
