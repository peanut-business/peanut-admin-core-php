<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\FileMedia\Application\FileMediaException;
use PeanutAdmin\FileMedia\Application\FileObject;
use PeanutAdmin\FileMedia\Application\UploadDescriptor;
use PeanutAdmin\FileMedia\Storage\StoredObject;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\db\PDOConnection;

final readonly class FileStore
{
    public function __construct(private PDOConnection $connection) {}

    public function create(
        TenantContext $context,
        string $fileKey,
        UploadDescriptor $upload,
        StoredObject $stored,
    ): FileObject {
        $this->assertTenantActor($context);
        $now = $this->databaseNow();
        $this->connection->execute(<<<'SQL'
INSERT INTO pa_file_object (
  file_key, tenant_id, storage_provider_key, storage_key, original_name,
  media_type, size_bytes, sha256, status, created_by_member_id, revision,
  created_at, updated_at, archived_at
) VALUES (
  :file_key, :tenant_id, :provider_key, :storage_key, :original_name,
  :media_type, :size_bytes, :sha256, 'ready', :member_id, 1,
  :created_at, :updated_at, NULL
)
SQL, [
            'file_key' => $fileKey,
            'tenant_id' => $context->tenantId,
            'provider_key' => $stored->providerKey,
            'storage_key' => $stored->storageKey,
            'original_name' => $upload->originalName,
            'media_type' => $upload->mediaType,
            'size_bytes' => $upload->sizeBytes,
            'sha256' => $upload->sha256,
            'member_id' => $context->memberId,
            'created_at' => $this->date($now),
            'updated_at' => $this->date($now),
        ]);

        return $this->require($context->tenantId, $fileKey, true);
    }

    /** @return array{items: list<FileObject>, page: int, page_size: int, total: int} */
    public function list(int $tenantId, string $status, int $page, int $pageSize): array
    {
        if (!in_array($status, ['ready', 'archived'], true) || $page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw FileMediaException::uploadInvalid('The file list query is invalid.');
        }
        $parameters = ['tenant_id' => $tenantId, 'status' => $status];
        $count = $this->one(
            'SELECT COUNT(*) AS aggregate FROM pa_file_object WHERE tenant_id = :tenant_id AND status = :status',
            $parameters,
        );
        $rows = $this->connection->query(sprintf(<<<'SQL'
SELECT * FROM pa_file_object
WHERE tenant_id = :tenant_id AND status = :status
ORDER BY id DESC LIMIT %d OFFSET %d
SQL, $pageSize, ($page - 1) * $pageSize), $parameters);
        $items = array_values(array_map(fn(array $row): FileObject => $this->map($row), $rows));

        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => (int) ($count['aggregate'] ?? 0)];
    }

    public function get(int $tenantId, string $fileKey, bool $includeArchived = true): FileObject
    {
        return $this->require($tenantId, $fileKey, $includeArchived);
    }

    public function getForDownload(int $tenantId, string $fileKey): FileObject
    {
        $row = $this->row($tenantId, $fileKey, true, false, true);
        if ($row === null) {
            throw FileMediaException::notFound();
        }

        return $this->map($row);
    }

    public function archive(TenantContext $context, string $fileKey, int $expectedRevision): FileObject
    {
        $this->assertTenantActor($context);
        $row = $this->row($context->tenantId, $fileKey, true);
        if ($row === null) {
            throw FileMediaException::notFound();
        }
        $current = $this->map($row);
        if ($current->status === 'archived') {
            if ($current->revision !== $expectedRevision) {
                throw FileMediaException::revisionConflict();
            }

            return $current;
        }
        if ($current->revision !== $expectedRevision) {
            throw FileMediaException::revisionConflict();
        }
        $now = $this->databaseNow();
        $affected = $this->connection->execute(<<<'SQL'
UPDATE pa_file_object
SET status = 'archived', revision = revision + 1, archived_at = :archived_at, updated_at = :updated_at
WHERE id = :id AND tenant_id = :tenant_id AND status = 'ready' AND revision = :revision
SQL, [
            'archived_at' => $this->date($now),
            'updated_at' => $this->date($now),
            'id' => $current->id,
            'tenant_id' => $context->tenantId,
            'revision' => $expectedRevision,
        ]);
        if ($affected !== 1) {
            throw FileMediaException::revisionConflict();
        }

        return $this->require($context->tenantId, $fileKey, true);
    }

    private function require(int $tenantId, string $fileKey, bool $includeArchived): FileObject
    {
        $row = $this->row($tenantId, $fileKey, false, $includeArchived);
        if ($row === null) {
            throw FileMediaException::notFound();
        }

        return $this->map($row);
    }

    /** @return array<string, mixed>|null */
    private function row(
        int $tenantId,
        string $fileKey,
        bool $lock,
        bool $includeArchived = true,
        bool $sharedLock = false,
    ): ?array {
        $sql = 'SELECT * FROM pa_file_object WHERE tenant_id = :tenant_id AND file_key = :file_key';
        if (!$includeArchived) {
            $sql .= " AND status = 'ready'";
        }
        if ($lock) {
            $sql .= $sharedLock ? ' FOR SHARE' : ' FOR UPDATE';
        }
        return $this->one($sql, ['tenant_id' => $tenantId, 'file_key' => $fileKey]);
    }

    private function assertTenantActor(TenantContext $context): void
    {
        $row = $this->one(<<<'SQL'
SELECT account_id FROM pa_tenant_member
WHERE tenant_id = :tenant_id AND id = :member_id AND account_id = :account_id AND status = 'active'
SQL, [
            'tenant_id' => $context->tenantId,
            'member_id' => $context->memberId,
            'account_id' => $context->accountId,
        ]);
        if ($row === null) {
            throw FileMediaException::notFound();
        }
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): FileObject
    {
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
            $this->timestamp($row['created_at']),
            $this->timestamp($row['updated_at']),
            $row['archived_at'] === null ? null : $this->timestamp($row['archived_at']),
        );
    }

    private function databaseNow(): DateTimeImmutable
    {
        $value = $this->one('SELECT UTC_TIMESTAMP(3) AS current_time')['current_time'] ?? null;
        if (!is_string($value)) {
            throw FileMediaException::internal();
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function timestamp(mixed $value): string
    {
        if (!is_string($value)) {
            throw FileMediaException::internal();
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format('Y-m-d H:i:s.v') !== $value
        ) {
            throw FileMediaException::internal();
        }

        return $date->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $parameters = []): ?array
    {
        $row = $this->connection->query($sql, $parameters)[0] ?? null;

        return is_array($row) ? $row : null;
    }
}
