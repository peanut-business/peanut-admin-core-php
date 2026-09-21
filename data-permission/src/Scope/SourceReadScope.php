<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Scope;

/** One authorized source keeps its object and field restrictions together. */
final readonly class SourceReadScope
{
    /**
     * @param non-empty-list<int>|null $objectIds Null means all objects in this authorized source.
     * @param list<int> $deniedObjectIds
     * @param non-empty-list<string> $fields
     */
    public function __construct(
        public int $tenantId,
        public ?array $objectIds,
        public array $deniedObjectIds,
        public array $fields,
        public string $revision,
    ) {
        if ($tenantId < 1 || $objectIds === [] || $fields === [] || $revision === ''
            || ($objectIds !== null && count($objectIds) > 500) || count($deniedObjectIds) > 500
            || !array_is_list($fields) || !array_is_list($deniedObjectIds)
            || ($objectIds !== null && !array_is_list($objectIds))) {
            throw new \InvalidArgumentException('READ_SCOPE_INVALID');
        }
        $allowed = [];
        foreach ($objectIds ?? [] as $id) {
            if (!is_int($id) || $id < 1 || isset($allowed[$id])) {
                throw new \InvalidArgumentException('READ_SCOPE_INVALID');
            }
            $allowed[$id] = true;
        }
        $denied = [];
        foreach ($deniedObjectIds as $id) {
            if (!is_int($id) || $id < 1 || isset($denied[$id])) {
                throw new \InvalidArgumentException('READ_SCOPE_INVALID');
            }
            $denied[$id] = true;
        }
        $knownFields = [];
        foreach ($fields as $field) {
            if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field) !== 1
                || isset($knownFields[$field])) {
                throw new \InvalidArgumentException('READ_SCOPE_INVALID');
            }
            $knownFields[$field] = true;
        }
    }
}
