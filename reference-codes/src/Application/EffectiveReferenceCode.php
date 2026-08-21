<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Application;

final readonly class EffectiveReferenceCode
{
    /**
     * @param null|array{
     *   revision: int,
     *   label: string,
     *   metadata: array<string, null|bool|int|float|string>,
     *   status: string,
     *   sort_order: int,
     *   effective_at: string,
     *   expires_at: ?string
     * } $effective
     * @param array{
     *   revision: int,
     *   label: string,
     *   metadata: array<string, null|bool|int|float|string>,
     *   status: string,
     *   sort_order: int,
     *   effective_at: string,
     *   expires_at: ?string
     * } $latestVersion
     */
    public function __construct(
        public string $moduleKey,
        public string $setKey,
        public string $code,
        public string $lifecycle,
        public int $revision,
        public string $etag,
        public ?array $effective,
        public string $createdAt,
        public string $updatedAt,
        public ?string $retiredAt,
        public string $asOf,
        private array $latestVersion,
    ) {}

    public function selectable(): bool
    {
        return $this->lifecycle === 'active'
            && $this->effective !== null
            && $this->effective['status'] === 'active';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'module_key' => $this->moduleKey,
            'set_key' => $this->setKey,
            'code' => $this->code,
            'lifecycle' => $this->lifecycle,
            'revision' => $this->revision,
            'etag' => $this->etag,
            'effective' => $this->effective,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'retired_at' => $this->retiredAt,
        ];
    }

    /** @param list<string> $changedFields
     * @return array<string, bool|int|string|null>
     */
    public function auditMetadata(array $changedFields): array
    {
        $allowed = ['effective_at', 'expires_at', 'label', 'metadata', 'sort_order', 'status'];
        $changedFields = array_values(array_unique(array_filter(
            $changedFields,
            static fn(string $field): bool => in_array($field, $allowed, true),
        )));
        sort($changedFields, SORT_STRING);

        return [
            'module_key' => $this->moduleKey,
            'set_key' => $this->setKey,
            'code' => $this->code,
            'changed_fields' => implode(',', $changedFields),
            'effective_status' => $this->latestVersion['status'],
            'effective_at' => $this->latestVersion['effective_at'],
            'expires_at' => $this->latestVersion['expires_at'],
            'sort_order' => $this->latestVersion['sort_order'],
            'revision' => $this->revision,
        ];
    }
}
