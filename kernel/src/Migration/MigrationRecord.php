<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Migration;

final readonly class MigrationRecord
{
    public function __construct(
        public string $moduleKey,
        public string $migrationKey,
        public string $moduleVersion,
        public string $checksum,
        public string $status,
    ) {}
}
