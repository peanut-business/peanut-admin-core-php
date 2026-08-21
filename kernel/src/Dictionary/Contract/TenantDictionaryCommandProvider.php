<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Dictionary\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Dictionary\DictionaryEntry;
use PeanutAdmin\Kernel\Dictionary\DictionaryType;

interface TenantDictionaryCommandProvider
{
    /** @param array<string,mixed> $values */
    public function createType(TenantContext $context, array $values): DictionaryType;

    /** @param array<string,mixed> $values */
    public function replaceType(TenantContext $context, int $id, array $values): DictionaryType;

    public function deleteType(TenantContext $context, int $id): void;

    public function setTypeDisabled(TenantContext $context, int $id, bool $disabled): void;

    /** @param array<string,mixed> $values */
    public function createEntry(TenantContext $context, array $values): DictionaryEntry;

    /** @param array<string,mixed> $values */
    public function replaceEntry(TenantContext $context, int $id, array $values): DictionaryEntry;

    public function deleteEntry(TenantContext $context, int $id): void;

    public function setEntryDisabled(TenantContext $context, int $id, bool $disabled): void;
}
