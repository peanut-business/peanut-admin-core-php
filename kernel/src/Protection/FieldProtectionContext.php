<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Protection;

/** Identity of a persisted field, resolved from the tenant-scoped record. */
final readonly class FieldProtectionContext
{
    public function __construct(
        public int $tenantId,
        public string $resourceKey,
        public string $resourceId,
        public string $fieldKey,
    ) {
        if ($tenantId < 1 || $resourceKey === '' || strlen($resourceKey) > 160
            || $resourceId === '' || strlen($resourceId) > 128
            || $fieldKey === '' || strlen($fieldKey) > 128) {
            throw FieldProtectionException::unavailable();
        }
    }

    public function authenticatedData(string $keyId): string
    {
        if ($keyId === '' || strlen($keyId) > 64) {
            throw FieldProtectionException::unavailable();
        }

        $data = "peanut.business-field.v1\x00";
        foreach ([(string) $this->tenantId, $this->resourceKey, $this->resourceId, $this->fieldKey, $keyId] as $part) {
            $data .= pack('N', strlen($part)) . $part;
        }

        return $data;
    }
}
