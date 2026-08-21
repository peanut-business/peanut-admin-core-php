<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Target;

final readonly class TargetCatalogQuery
{
    public function __construct(
        public string $targetResourceKey,
        public string $search = '',
        public int $page = 1,
        public int $pageSize = 20,
        public string $targetRole = 'primary',
        public string $mode = 'runtime',
    ) {}
}
