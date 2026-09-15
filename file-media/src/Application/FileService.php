<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Application;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\FileMedia\Media\ImageMetadataInspector;
use PeanutAdmin\FileMedia\Model\FileDeliveryPolicyRecord;
use PeanutAdmin\FileMedia\Model\FileImageMetadataRecord;
use PeanutAdmin\FileMedia\Model\FileObjectRecord;
use PeanutAdmin\FileMedia\Storage\StorageProvider;
use PeanutAdmin\FileMedia\Storage\StoredObject;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleAvailabilityService;
use PeanutAdmin\Kernel\Persistence\Model\TenantMember;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use think\db\Raw;
use think\facade\Db;
use think\Model;
use think\model\type\DateTime as ThinkPhpDateTime;
use Throwable;

final readonly class FileService
{
    public function __construct(
        private StorageProvider $storage,
        private UploadPolicy $policy,
        private AuditService $audit,
        private ModuleAvailabilityService $modules,
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

            return Db::transaction(function () use ($context, $fileKey, $upload, $stored): FileObject {
                $scope = self::scope($context);
                $this->modules->assertAvailable($scope, 'peanut.file-media', self::now(), true);
                $this->assertTenantActor($context, $scope);
                $now = new Raw('UTC_TIMESTAMP(3)');
                $record = new FileObjectRecord();
                $record->save([
                    'file_key' => $fileKey,
                    'tenant_id' => $scope->tenantId(),
                    'storage_provider_key' => $stored->providerKey,
                    'storage_key' => $stored->storageKey,
                    'original_name' => $upload->originalName,
                    'media_type' => $upload->mediaType,
                    'size_bytes' => $upload->sizeBytes,
                    'sha256' => $upload->sha256,
                    'status' => 'ready',
                    'created_by_member_id' => $context->memberId,
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'archived_at' => null,
                ]);
                $this->recordImage($scope, (int) $record->getAttr('id'), $upload->sourcePath, $upload->mediaType);

                $file = $this->require($scope, $fileKey, true);
                $this->audit->tenantMember(
                    $context,
                    'tenant.file.created',
                    'peanut.file-media.create',
                    'file',
                    $file->fileKey,
                    $file->auditMetadata(),
                );

                return $file;
            });
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
        if (!in_array($status, ['ready', 'archived'], true) || $page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw FileMediaException::uploadInvalid('The file list query is invalid.');
        }
        $query = FileObjectRecord::scope('tenant', self::scope($context))->where('status', $status);
        $total = (clone $query)->count();
        $records = $query->order('id', 'desc')->page($page, $pageSize)->select();
        $items = [];
        foreach ($records as $record) {
            $items[] = $this->map($record);
        }

        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    public function detail(TenantContext $context, string $fileKey): FileObject
    {
        self::assertFileKey($fileKey);

        return $this->require(self::scope($context), $fileKey, true);
    }

    public function downloadable(TenantScope $scope, string $fileKey): FileObject
    {
        self::assertFileKey($fileKey);

        return $this->require($scope, $fileKey, false);
    }

    /** @return resource */
    public function content(TenantContext $context, string $fileKey)
    {
        $file = Db::transaction(function () use ($context, $fileKey): FileObject {
            $scope = self::scope($context);
            $this->modules->assertAvailable($scope, 'peanut.file-media', self::now(), true);
            $file = $this->downloadable($scope, $fileKey);
            $this->audit->tenantMember(
                $context,
                'tenant.file.downloaded',
                'peanut.file-media.read',
                'file',
                $file->fileKey,
                $file->auditMetadata(),
            );

            return $file;
        });
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
        $revision = self::revision($ifMatch);

        return Db::transaction(function () use ($context, $fileKey, $revision): FileObject {
            $scope = self::scope($context);
            $this->modules->assertAvailable($scope, 'peanut.file-media', self::now(), true);
            $this->assertTenantActor($context, $scope);
            $record = FileObjectRecord::scope('tenant', $scope)
                ->where('file_key', $fileKey)
                ->lock(true)
                ->find();
            if (!$record instanceof FileObjectRecord) {
                throw FileMediaException::notFound();
            }
            $current = $this->map($record);
            if ($current->revision !== $revision) {
                throw FileMediaException::revisionConflict();
            }
            if ($current->status === 'archived') {
                return $current;
            }
            $now = new Raw('UTC_TIMESTAMP(3)');
            $affected = FileObjectRecord::scope('tenant', $scope)
                ->where('id', $current->id)
                ->where('status', 'ready')
                ->where('revision', $revision)
                ->update([
                    'status' => 'archived',
                    'revision' => new Raw('revision + 1'),
                    'archived_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($affected !== 1) {
                throw FileMediaException::revisionConflict();
            }

            $file = $this->require($scope, $fileKey, true);
            $this->audit->tenantMember(
                $context,
                'tenant.file.archived',
                'peanut.file-media.delete',
                'file',
                $file->fileKey,
                $file->auditMetadata(),
            );

            return $file;
        });
    }

    /** @return array{items:list<array<string,mixed>>,page:int,page_size:int,total:int} */
    public function assets(TenantContext $context, int $page, int $pageSize): array
    {
        if ($page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw FileMediaException::deliveryInvalid();
        }
        $scope = self::scope($context);
        $query = FileObjectRecord::scope('tenant', $scope)
            ->alias('file')
            ->join('file_image_metadata metadata', 'metadata.tenant_id = file.tenant_id AND metadata.file_object_id = file.id')
            ->where('file.status', 'ready');
        $total = (clone $query)->count();
        $records = $query
            ->field('file.*,metadata.width,metadata.height')
            ->order('file.id', 'desc')
            ->page($page, $pageSize)
            ->select();
        $items = [];
        foreach ($records as $record) {
            $file = $this->map($record);
            $items[] = [
                'file' => $file,
                'media_type' => $file->mediaType,
                    'width' => (int) $record['width'],
                    'height' => (int) $record['height'],
            ];
        }

        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    public function compensate(StoredObject $stored): void
    {
        try {
            $this->storage->remove($stored->storageKey);
        } catch (Throwable) {
            throw FileMediaException::storageUnavailable();
        }
    }

    private function require(TenantScope $scope, string $fileKey, bool $includeArchived): FileObject
    {
        $query = FileObjectRecord::scope('tenant', $scope)->where('file_key', $fileKey);
        if (!$includeArchived) {
            $query->where('status', 'ready');
        }
        $record = $query->find();
        if (!$record instanceof FileObjectRecord) {
            throw FileMediaException::notFound();
        }

        return $this->map($record);
    }

    private function assertTenantActor(TenantContext $context, TenantScope $scope): void
    {
        $member = TenantMember::scope('tenant', $scope)
            ->where('id', $context->memberId)
            ->where('account_id', $context->accountId)
            ->where('status', 'active')
            ->find();
        if (!$member instanceof TenantMember) {
            throw FileMediaException::notFound();
        }
    }

    private function recordImage(TenantScope $scope, int $fileId, string $sourcePath, string $mediaType): void
    {
        if (!in_array($mediaType, ['image/jpeg', 'image/png'], true)) {
            return;
        }
        $metadata = (new ImageMetadataInspector())->inspect($sourcePath);
        $now = new Raw('UTC_TIMESTAMP(3)');
        (new FileImageMetadataRecord())->save([
            'tenant_id' => $scope->tenantId(),
            'file_object_id' => $fileId,
            'width' => $metadata->width,
            'height' => $metadata->height,
            'media_type' => $metadata->mediaType,
            'created_at' => $now,
        ]);
        (new FileDeliveryPolicyRecord())->save([
            'tenant_id' => $scope->tenantId(),
            'file_object_id' => $fileId,
            'visibility' => 'private',
            'revision' => 1,
            'updated_at' => $now,
        ]);
    }

    /** @param Model|array<string, mixed> $record */
    private function map(Model|array $record): FileObject
    {
        $row = $record instanceof Model ? $record->getData() : $record;

        return new FileObject(
            (int) $row['id'],
            (string) $row['file_key'],
            (int) $row['tenant_id'],
            (string) $row['storage_provider_key'],
            (string) $row['storage_key'],
            (string) $row['original_name'],
            (string) $row['media_type'],
            (int) $row['size_bytes'],
            (string) $row['sha256'],
            (string) $row['status'],
            (int) $row['created_by_member_id'],
            (int) $row['revision'],
            self::timestamp($row['created_at']),
            self::timestamp($row['updated_at']),
            $row['archived_at'] === null ? null : self::timestamp($row['archived_at']),
        );
    }

    private static function scope(TenantContext $context): TenantScope
    {
        return TenantScope::fromTrustedContext($context->tenantId, $context->requestId);
    }

    private static function revision(?string $ifMatch): int
    {
        if (!is_string($ifMatch) || preg_match('/^"rev-([1-9][0-9]*)"$/D', $ifMatch, $matches) !== 1) {
            throw FileMediaException::preconditionRequired();
        }
        $revision = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($revision)) {
            throw FileMediaException::preconditionRequired();
        }

        return $revision;
    }

    private static function assertFileKey(string $fileKey): void
    {
        if (preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) !== 1) {
            throw FileMediaException::notFound();
        }
    }

    private static function timestamp(mixed $value): string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        }
        if ($value instanceof ThinkPhpDateTime) {
            $value = $value->format('Y-m-d H:i:s.v');
        }
        if (!is_string($value)) {
            throw FileMediaException::internal();
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw FileMediaException::internal();
        }

        return $date->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
