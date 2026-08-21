<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Migration;

interface OwnedMigration
{
    public static function moduleKey(): string;

    /** @return list<string> */
    public static function ownedTables(): array;

    public static function reversible(): bool;
}
