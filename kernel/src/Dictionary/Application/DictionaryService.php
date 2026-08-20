<?php
declare(strict_types=1);

namespace PeanutAdmin\Kernel\Dictionary\Application;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Dictionary\Contract\SystemDictionaryProvider;
use PeanutAdmin\Kernel\Dictionary\Contract\TenantDictionaryCommandProvider;
use PeanutAdmin\Kernel\Dictionary\Contract\TenantDictionaryQueryProvider;
use PeanutAdmin\Kernel\Dictionary\DictionaryEntry;
use PeanutAdmin\Kernel\Dictionary\DictionaryPage;
use PeanutAdmin\Kernel\Dictionary\DictionaryType;

final readonly class DictionaryService
{
    public function __construct(
        private TenantDictionaryQueryProvider $query,
        private TenantDictionaryCommandProvider $command,
        private SystemDictionaryProvider $system,
    ) {}

    /** @param array<string,mixed> $filters */
    public function types(TenantContext $context, array $filters, int $page, int $pageSize): DictionaryPage
    {
        return $this->query->types($context, $filters, $page, $pageSize);
    }

    /** @param array<string,mixed> $filters */
    public function entries(TenantContext $context, array $filters, int $page, int $pageSize): DictionaryPage
    {
        return $this->query->entries($context, $filters, $page, $pageSize);
    }

    public function type(TenantContext $context, int $id): ?DictionaryType { return $this->query->type($context, $id); }
    /** @return list<DictionaryType> */
    public function enabledTypes(TenantContext $context): array { return $this->query->enabledTypes($context); }
    public function entry(TenantContext $context, int $id): ?DictionaryEntry { return $this->query->entry($context, $id); }
    /** @param array<string,mixed> $values */
    public function createType(TenantContext $context, array $values): DictionaryType { return $this->command->createType($context, $values); }
    /** @param array<string,mixed> $values */
    public function replaceType(TenantContext $context, int $id, array $values): DictionaryType { return $this->command->replaceType($context, $id, $values); }
    public function deleteType(TenantContext $context, int $id): void { $this->command->deleteType($context, $id); }
    public function setTypeDisabled(TenantContext $context, int $id, bool $disabled): void { $this->command->setTypeDisabled($context, $id, $disabled); }
    /** @param array<string,mixed> $values */
    public function createEntry(TenantContext $context, array $values): DictionaryEntry { return $this->command->createEntry($context, $values); }
    /** @param array<string,mixed> $values */
    public function replaceEntry(TenantContext $context, int $id, array $values): DictionaryEntry { return $this->command->replaceEntry($context, $id, $values); }
    public function deleteEntry(TenantContext $context, int $id): void { $this->command->deleteEntry($context, $id); }
    public function setEntryDisabled(TenantContext $context, int $id, bool $disabled): void { $this->command->setEntryDisabled($context, $id, $disabled); }

    /** @return list<array<string,mixed>> */
    public function enabledByType(TenantContext $context, string $type): array
    {
        $system = $this->system->enabledEntriesByType($type);
        $tenant = $this->query->enabledEntriesByType($context, $type);
        $seen = [];
        $result = [];
        foreach ([...$system, ...$tenant] as $entry) {
            if (!$entry instanceof DictionaryEntry || isset($seen[$entry->value])) continue;
            $seen[$entry->value] = true;
            $result[] = $entry->toArray();
        }
        return $result;
    }
}
