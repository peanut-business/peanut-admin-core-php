<?php

declare(strict_types=1);

use think\migration\Migrator;

final class AddAuthEventIpIndex extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE `pa_auth_security_event`
ADD KEY `idx_auth_event_ip` (`ip_address`, `occurred_at`)
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE `pa_auth_security_event`
DROP INDEX `idx_auth_event_ip`
SQL);
    }
}
