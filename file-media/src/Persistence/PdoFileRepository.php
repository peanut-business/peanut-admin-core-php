<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PeanutAdmin\FileMedia\Application\FileMediaException;
use PeanutAdmin\FileMedia\Application\FileObject;
use PeanutAdmin\FileMedia\Application\UploadDescriptor;
use PeanutAdmin\FileMedia\Storage\StoredObject;
use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class PdoFileRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(
        TenantContext $context,
        string $fileKey,
        UploadDescriptor $upload,
        StoredObject $stored,
    ): FileObject {
        $this->assertTenantActor($context);
        $now = $this->databaseNow();
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_file_object (
  file_key, tenant_id, storage_provider_key, storage_key, original_name,
  media_type, size_bytes, sha256, status, created_by_member_id, revision,
  created_at, updated_at, archived_at
) VALUES (
  :file_key, :tenant_id, :provider_key, :storage_key, :original_name,
  :media_type, :size_bytes, :sha256, 'ready', :member_id, 1,
  :created_at, :updated_at, NULL
)
SQL);
        $statement->execute([
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
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM pa_file_object WHERE tenant_id = :tenant_id AND status = :status',
        );
        $count->execute(['tenant_id' => $tenantId, 'status' => $status]);
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT * FROM pa_file_object
WHERE tenant_id = :tenant_id AND status = :status
ORDER BY id DESC LIMIT :limit OFFSET :offset
SQL);
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':status', $status);
        $statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $statement->execute();
        $items = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $items[] = $this->map($row);
        }

        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
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
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_file_object
SET status = 'archived', revision = revision + 1, archived_at = :archived_at, updated_at = :updated_at
WHERE id = :id AND tenant_id = :tenant_id AND status = 'ready' AND revision = :revision
SQL);
        $statement->execute([
            'archived_at' => $this->date($now),
            'updated_at' => $this->date($now),
            'id' => $current->id,
            'tenant_id' => $context->tenantId,
            'revision' => $expectedRevision,
        ]);
        if ($statement->rowCount() !== 1) {
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
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['tenant_id' => $tenantId, 'file_key' => $fileKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function assertTenantActor(TenantContext $context): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT account_id FROM pa_tenant_member
WHERE tenant_id = :tenant_id AND id = :member_id AND account_id = :account_id AND status = 'active'
SQL);
        $statement->execute([
            'tenant_id' => $context->tenantId,
            'member_id' => $context->memberId,
            'account_id' => $context->accountId,
        ]);
        if ($statement->fetchColumn() === false) {
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
        $statement = $this->pdo->query('SELECT UTC_TIMESTAMP(3)');
        $value = $statement === false ? false : $statement->fetchColumn();
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
}
