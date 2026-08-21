<?php

declare(strict_types=1);

use think\migration\Migrator;

final class GeneralizePaTenantClients extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE `pa_login_challenge`
ADD COLUMN `client_key` VARCHAR(64) NOT NULL DEFAULT 'admin-web' AFTER `purpose`,
ADD CONSTRAINT `chk_login_challenge_client`
CHECK (REGEXP_LIKE(`client_key`, '^[a-z][a-z0-9-]{0,63}$', 'c'))
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE `pa_login_challenge`
ALTER COLUMN `client_key` DROP DEFAULT
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE `pa_tenant_session`
DROP CHECK `chk_tenant_session_client`,
ADD CONSTRAINT `chk_tenant_session_client`
CHECK (REGEXP_LIKE(`client_key`, '^[a-z][a-z0-9-]{0,63}$', 'c'))
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE `pa_tenant_session`
DROP CHECK `chk_tenant_session_client`,
ADD CONSTRAINT `chk_tenant_session_client` CHECK (`client_key` = 'admin-web')
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE `pa_login_challenge`
DROP CHECK `chk_login_challenge_client`,
DROP COLUMN `client_key`
SQL);
    }
}
