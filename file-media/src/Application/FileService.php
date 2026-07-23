<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Application;

use PeanutAdmin\FileMedia\Persistence\PdoFileRepository;
use PeanutAdmin\FileMedia\Storage\StorageProvider;
use PeanutAdmin\FileMedia\Storage\StoredObject;
use PeanutAdmin\Kernel\Auth\TenantContext;
use Throwable;

final readonly class FileService
{
    public function __construct(
        private PdoFileRepository $repository,
        private StorageProvider $storage,
        private UploadPolicy $policy,
    ) {}

    /** @param null|callable(StoredObject): void $storedCallback */
    public function upload(
        TenantContext $context,
        string $sourcePath,
        string $originalName,
        ?callable $storedCallback = null,
    ): FileObject {
        $upload = $this->policy->inspect($sourcePath, $originalName);
        $fileKey = 'file_' . bin2hex(random_bytes(16));
        $stored = null;
        try {
            $stored = $this->storage->store($context->tenantId, $fileKey, $upload->sourcePath);
            if ($storedCallback !== null) {
                $storedCallback($stored);
            }
            if (!hash_equals($this->storage->key(), $stored->providerKey)) {
                throw FileMediaException::storageUnavailable();
            }

            return $this->repository->create($context, $fileKey, $upload, $stored);
        } catch (FileMediaException $exception) {
            if ($stored instanceof StoredObject) {
                $this->compensate($stored);
            }
            throw $exception;
        } catch (Throwable) {
            if (!$stored instanceof StoredObject) {
                throw FileMediaException::storageUnavailable();
            }
            $this->compensate($stored);
            throw FileMediaException::internal();
        }
    }

    /** @return array{items: list<FileObject>, page: int, page_size: int, total: int} */
    public function list(TenantContext $context, string $status, int $page, int $pageSize): array
    {
        return $this->repository->list($context->tenantId, $status, $page, $pageSize);
    }

    public function detail(TenantContext $context, string $fileKey): FileObject
    {
        self::assertFileKey($fileKey);

        return $this->repository->get($context->tenantId, $fileKey);
    }

    /** @return resource */
    public function content(TenantContext $context, string $fileKey)
    {
        self::assertFileKey($fileKey);
        $file = $this->repository->getForDownload($context->tenantId, $fileKey);
        if (!hash_equals($this->storage->key(), $file->storageProviderKey)) {
            throw FileMediaException::storageUnavailable();
        }
        try {
            return $this->storage->open($file->storageKey);
        } catch (Throwable) {
            throw FileMediaException::storageUnavailable();
        }
    }

    public function archive(TenantContext $context, string $fileKey, ?string $ifMatch): FileObject
    {
        self::assertFileKey($fileKey);
        if (!is_string($ifMatch) || preg_match('/^"rev-([1-9][0-9]*)"$/D', $ifMatch, $matches) !== 1) {
            throw FileMediaException::preconditionRequired();
        }
        $revision = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($revision)) {
            throw FileMediaException::preconditionRequired();
        }

        return $this->repository->archive($context, $fileKey, $revision);
    }

    private static function assertFileKey(string $fileKey): void
    {
        if (preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) !== 1) {
            throw FileMediaException::notFound();
        }
    }

    public function compensate(StoredObject $stored): void
    {
        try {
            $this->storage->remove($stored->storageKey);
        } catch (Throwable) {
            throw FileMediaException::storageUnavailable();
        }
    }
}
