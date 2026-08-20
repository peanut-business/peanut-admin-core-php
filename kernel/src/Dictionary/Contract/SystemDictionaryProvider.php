<?php
declare(strict_types=1);

namespace PeanutAdmin\Kernel\Dictionary\Contract;

use PeanutAdmin\Kernel\Dictionary\DictionaryEntry;

interface SystemDictionaryProvider
{
    /** @return list<DictionaryEntry> */
    public function enabledEntriesByType(string $type): array;
}
