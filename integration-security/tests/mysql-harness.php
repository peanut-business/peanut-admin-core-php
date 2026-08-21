<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach ([
        'PeanutAdmin\\IntegrationSecurity\\' => $root . '/packages/php/integration-security/src/',
        'PeanutAdmin\\Kernel\\' => $root . '/packages/php/kernel/src/',
    ] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use PeanutAdmin\IntegrationSecurity\Application\MachineIdentityService;
use PeanutAdmin\IntegrationSecurity\Application\MachineScopeCatalog;
use PeanutAdmin\IntegrationSecurity\Application\MachineScopeGrantPolicy;
use PeanutAdmin\IntegrationSecurity\Application\MachineScopeGrantResolver;
use PeanutAdmin\IntegrationSecurity\Application\SessionSecurityService;
use PeanutAdmin\IntegrationSecurity\Application\WebhookDeliveryLogService;
use PeanutAdmin\IntegrationSecurity\Application\WebhookService;
use PeanutAdmin\IntegrationSecurity\Crypto\AesGcmWebhookSecretProtector;
use PeanutAdmin\IntegrationSecurity\Database\Schema;
use PeanutAdmin\IntegrationSecurity\Package;
use PeanutAdmin\IntegrationSecurity\Persistence\PdoIntegrationSecurityRepository;
use PeanutAdmin\IntegrationSecurity\Webhook\HostAddressResolver;
use PeanutAdmin\IntegrationSecurity\Webhook\TrustedWebhookEvent;
use PeanutAdmin\IntegrationSecurity\Webhook\TrustedWebhookPublisher;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookDestinationPolicy;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookDispatcher;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookRequest;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookResponse;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookTransport;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . var_export($actual, true));
    }
}
function truth(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function guardedDatabase(PDO $pdo): string
{
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($database) || preg_match('/^peanut_c03_[0-9a-f]{8}$/D', $database) !== 1) {
        throw new RuntimeException('Refusing destructive SQL outside a unique peanut_c03 database.');
    }
    return $database;
}
function dropTable(PDO $pdo, string $table): void
{
    guardedDatabase($pdo);
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
}
function operation(string $name, int $tenantId, int $accountId, int $memberId, string $sessionKey): AuthorizedOperationContext
{
    $session = new ValidatedTenantSession(1, $sessionKey, $tenantId, $accountId, $memberId, 'admin-web', new DateTimeImmutable('2026-07-24T10:00:00Z'), 1);
    return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
        TenantContext::fromValidatedSession($session, 'req_c03_mysql_' . $tenantId),
        Package::RESOURCE_KEY,
        $name,
        [],
        hash('sha256', 'basis-' . $name),
    ));
}

$dsn = getenv('C03_MYSQL_DSN');
$user = getenv('C03_MYSQL_USER');
$password = getenv('C03_MYSQL_PASSWORD');
if (!is_string($dsn) || $dsn === '' || !is_string($user) || !is_string($password)) {
    throw new RuntimeException('C03 MySQL environment is incomplete.');
}
$serverDsn = preg_replace('/;dbname=[^;]*/i', '', $dsn);
if (!is_string($serverDsn)) {
    throw new RuntimeException('C03 MySQL DSN is invalid.');
}
$pdo = new PDO($serverDsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$database = 'peanut_c03_' . bin2hex(random_bytes(4));
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");
same($database, guardedDatabase($pdo), 'unique database selected');

$baseTables = ['pa_tenant_session_token', 'pa_tenant_session', 'pa_tenant_member', 'pa_account', 'pa_tenant'];
$drop = [...array_reverse(Schema::tableNames()), ...$baseTables];
try {
    foreach ($drop as $table) {
        dropTable($pdo, $table);
    }
    $pdo->exec("CREATE TABLE pa_tenant (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE pa_account (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE pa_tenant_member (id BIGINT UNSIGNED NOT NULL, tenant_id BIGINT UNSIGNED NOT NULL, account_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_member_tenant_id (tenant_id,id), CONSTRAINT fk_member_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant(id), CONSTRAINT fk_member_account FOREIGN KEY (account_id) REFERENCES pa_account(id)) ENGINE=InnoDB");
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant_session (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, session_key CHAR(26) NOT NULL, tenant_id BIGINT UNSIGNED NOT NULL,
 account_id BIGINT UNSIGNED NOT NULL, tenant_member_id BIGINT UNSIGNED NOT NULL, client_key VARCHAR(64) NOT NULL,
 status VARCHAR(16) NOT NULL, issued_at DATETIME(3) NOT NULL, last_seen_at DATETIME(3) NOT NULL,
 idle_expires_at DATETIME(3) NOT NULL, absolute_expires_at DATETIME(3) NOT NULL, ip_address VARCHAR(45) NULL,
 user_agent_hash CHAR(64) NULL, revoked_at DATETIME(3) NULL, revoke_reason VARCHAR(64) NULL,
 created_at DATETIME(3) NOT NULL, updated_at DATETIME(3) NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uk_session_key(session_key), CONSTRAINT fk_session_member FOREIGN KEY(tenant_id,tenant_member_id) REFERENCES pa_tenant_member(tenant_id,id)
) ENGINE=InnoDB
SQL);
    $pdo->exec("CREATE TABLE pa_tenant_session_token (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, session_id BIGINT UNSIGNED NOT NULL, token_type VARCHAR(16) NOT NULL, token_hash CHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, revoked_at DATETIME(3) NULL, PRIMARY KEY(id), CONSTRAINT fk_session_token FOREIGN KEY(session_id) REFERENCES pa_tenant_session(id)) ENGINE=InnoDB");
    foreach (Schema::tableNames() as $table) {
        $pdo->exec(Schema::createSql($table));
    }

    $pdo->exec('INSERT INTO pa_tenant(id) VALUES (101),(102)');
    $pdo->exec('INSERT INTO pa_account(id) VALUES (301),(302),(303)');
    $pdo->exec('INSERT INTO pa_tenant_member(id,tenant_id,account_id) VALUES (501,101,301),(502,101,302),(503,102,303)');
    $session1 = '01J00000000000000000000000';
    $sessionOther = '01J00000000000000000000001';
    $sessionTenant2 = '01J00000000000000000000002';
    $insertSession = $pdo->prepare("INSERT INTO pa_tenant_session(session_key,tenant_id,account_id,tenant_member_id,client_key,status,issued_at,last_seen_at,idle_expires_at,absolute_expires_at,ip_address,user_agent_hash,created_at,updated_at) VALUES (:key,:tenant,:account,:member,'admin-web','active','2026-07-24 10:00:00.000','2026-07-24 10:00:00.000','2030-01-01 00:00:00.000','2030-01-02 00:00:00.000',:ip,:agent,'2026-07-24 10:00:00.000','2026-07-24 10:00:00.000')");
    foreach ([[$session1,101,301,501,'203.0.113.42'],[$sessionOther,101,302,502,'198.51.100.9'],[$sessionTenant2,102,303,503,'192.0.2.7']] as [$key,$tenant,$account,$member,$ip]) {
        $insertSession->execute(['key' => $key,'tenant' => $tenant,'account' => $account,'member' => $member,'ip' => $ip,'agent' => hash('sha256', 'agent-' . $key)]);
        $pdo->prepare("INSERT INTO pa_tenant_session_token(session_id,token_type,token_hash,status) VALUES (:id,'refresh',:hash,'active')")->execute(['id' => (int) $pdo->lastInsertId(),'hash' => hash('sha256', 'token-' . $key)]);
    }

    $repository = new PdoIntegrationSecurityRepository($pdo);
    $scopeCatalog = new MachineScopeCatalog(['data.export.read', 'data.export.write']);
    $scopeResolver = new class implements MachineScopeGrantResolver {
        public function grantableScopes(AuthorizedOperationContext $context): array
        {
            return $context->tenantContext->memberId === 501 ? ['data.export.read'] : [];
        }
    };
    $machines = new MachineIdentityService($repository, new MachineScopeGrantPolicy($scopeCatalog, $scopeResolver));
    $machine = $machines->create(operation('machine-manage', 101, 301, 501, $session1), 'Export worker', ['data.export.read'], new DateTimeImmutable('2030-01-01T00:00:00Z'));
    same(1, count($machines->list(operation('machine-read', 101, 301, 501, $session1))), 'Tenant machine listed');
    same(0, count($machines->list(operation('machine-read', 102, 303, 503, $sessionTenant2))), 'machine Tenant isolated');
    same(101, $machines->authenticate($machine->token, ['data.export.read'], new DateTimeImmutable('2026-07-24T10:00:00Z'))->tenantId, 'machine token tenant');
    $stored = $pdo->query('SELECT token_digest FROM pa_integration_machine_identity')->fetchColumn();
    same(hash('sha256', $machine->token), $stored, 'only token digest stored');
    truth(!str_contains((string) $stored, $machine->token), 'plain token absent');
    $rotated = $machines->rotate(operation('machine-manage', 101, 301, 501, $session1), $machine->identity->identityKey, 1);
    try {
        $machines->authenticate($machine->token, [], new DateTimeImmutable('2026-07-24T10:00:00Z'));
        throw new RuntimeException('old token survived rotation');
    } catch (IntegrationSecurityException $exception) {
        same('MACHINE_TOKEN_INVALID', $exception->problemCode, 'old token rejected');
    }
    same(101, $machines->authenticate($rotated->token, [], new DateTimeImmutable('2026-07-24T10:00:00Z'))->tenantId, 'rotated token active');

    $resolver = new class implements HostAddressResolver {
        public function resolve(string $host): array
        {
            return $host === 'hooks.example.com' ? ['93.184.216.34'] : [];
        }
    };
    $policy = new WebhookDestinationPolicy($resolver);
    $protector = new AesGcmWebhookSecretProtector('mysql-key', base64_encode(str_repeat('m', 32)));
    $webhooks = new WebhookService($repository, $policy, $protector);
    $endpoint = $webhooks->create(operation('webhook-manage', 101, 301, 501, $session1), 'Audit receiver', 'https://hooks.example.com/events', ['audit.event.created']);
    $ciphertext = (string) $pdo->query('SELECT secret_ciphertext FROM pa_integration_webhook_endpoint')->fetchColumn();
    truth(!str_contains($ciphertext, $endpoint->signingSecret), 'webhook secret encrypted');
    $publisher = new TrustedWebhookPublisher($repository);
    $event = new TrustedWebhookEvent('event:2026:00000001', 'audit.event.created', ['id' => 42]);
    $first = $publisher->publish(101, $event, new DateTimeImmutable('2026-07-24T10:00:00Z'));
    $second = $publisher->publish(101, $event, new DateTimeImmutable('2026-07-24T10:01:00Z'));
    same($first, $second, 'delivery idempotent');
    same(1, (int) $pdo->query('SELECT COUNT(*) FROM pa_integration_webhook_delivery')->fetchColumn(), 'one delivery row');
    $transport = new class implements WebhookTransport {
        public ?WebhookRequest $last = null;
        public function send(WebhookRequest $request): WebhookResponse
        {
            $this->last = $request;
            return new WebhookResponse(204, 9);
        }
    };
    $dispatcher = new WebhookDispatcher($repository, $policy, $protector, $transport);
    truth($dispatcher->runOne(101, new DateTimeImmutable('2026-07-24T10:02:00Z')), 'delivery dispatched');
    same('delivered', (string) $pdo->query('SELECT status FROM pa_integration_webhook_delivery')->fetchColumn(), 'delivery terminal');
    same(1, (int) $pdo->query('SELECT COUNT(*) FROM pa_integration_webhook_attempt')->fetchColumn(), 'attempt logged');
    truth(preg_match('/^v1=[0-9a-f]{64}$/D', $transport->last?->headers['X-Peanut-Signature'] ?? '') === 1, 'signature emitted');
    same(false, $transport->last?->followRedirects, 'redirect disabled');

    $deliveryLogs = new WebhookDeliveryLogService($repository);
    $deliveryPage = $deliveryLogs->deliveries(operation('delivery-read', 101, 301, 501, $session1), 1, 20);
    same(1, $deliveryPage->total, 'delivery log paginated');
    $safeDelivery = json_encode($deliveryPage, JSON_THROW_ON_ERROR);
    truth(!str_contains($safeDelivery, 'hooks.example.com') && !str_contains($safeDelivery, $endpoint->signingSecret) && !str_contains($safeDelivery, 'payload'), 'delivery log redacted');
    same(1, $deliveryLogs->attempts(operation('delivery-read', 101, 301, 501, $session1), $first[0], 1, 20)->total, 'attempt log paginated');
    same(0, $deliveryLogs->deliveries(operation('delivery-read', 102, 303, 503, $sessionTenant2), 1, 20)->total, 'delivery log Tenant isolated');

    $retryEvent = new TrustedWebhookEvent('event:2026:00000002', 'audit.event.created', ['id' => 43]);
    $retryKey = $publisher->publish(101, $retryEvent, new DateTimeImmutable('2026-07-24T10:03:00Z'))[0];
    $pdo->exec("UPDATE pa_integration_webhook_delivery SET status='delivering', attempt_count=7, lease_digest='" . str_repeat('a', 64) . "', lease_expires_at='2026-07-24 10:03:30.000' WHERE delivery_key='" . $retryKey . "'");
    $recovered = $repository->claimDelivery(101, str_repeat('b', 64), 30, new DateTimeImmutable('2026-07-24T10:04:00Z'));
    same($retryKey, $recovered?->deliveryKey, 'expired lease below eight retryable and reclaimed');
    same(8, $recovered?->attemptNumber, 'reclaimed delivery advances attempt');
    same(1, (int) $pdo->query("SELECT COUNT(*) FROM pa_integration_webhook_attempt a JOIN pa_integration_webhook_delivery d ON d.id=a.delivery_id AND d.tenant_id=a.tenant_id WHERE d.delivery_key='" . $retryKey . "' AND a.attempt_number=7 AND a.outcome='retryable' AND a.error_code='WEBHOOK_LEASE_EXPIRED'")->fetchColumn(), 'retryable expired lease evidence');
    if ($recovered === null) {
        throw new RuntimeException('expired lease recovery missing');
    }
    $repository->failDelivery($recovered, 'WEBHOOK_TRANSPORT_FAILED', true, null, 0, new DateTimeImmutable('2026-07-24T10:04:01Z'));

    $leaseEvent = new TrustedWebhookEvent('event:2026:00000003', 'audit.event.created', ['id' => 44]);
    $expiredKey = $publisher->publish(101, $leaseEvent, new DateTimeImmutable('2026-07-24T10:03:00Z'))[0];
    $pdo->exec("UPDATE pa_integration_webhook_delivery SET status='delivering', attempt_count=8, lease_digest='" . str_repeat('a', 64) . "', lease_expires_at='2026-07-24 10:03:30.000' WHERE delivery_key='" . $expiredKey . "'");
    same(null, $repository->claimDelivery(101, str_repeat('b', 64), 30, new DateTimeImmutable('2026-07-24T10:04:00Z')), 'attempt eight lease is terminal');
    $expired = $pdo->query("SELECT status,lease_digest,lease_expires_at,last_error_code FROM pa_integration_webhook_delivery WHERE delivery_key='" . $expiredKey . "'")->fetch();
    same('permanent_failed', $expired['status'], 'attempt eight permanently failed');
    same(null, $expired['lease_digest'], 'expired lease digest cleared');
    same(null, $expired['lease_expires_at'], 'expired lease expiry cleared');
    same('WEBHOOK_LEASE_EXPIRED', $expired['last_error_code'], 'expired lease safe code');
    same(1, (int) $pdo->query("SELECT COUNT(*) FROM pa_integration_webhook_attempt a JOIN pa_integration_webhook_delivery d ON d.id=a.delivery_id AND d.tenant_id=a.tenant_id WHERE d.delivery_key='" . $expiredKey . "' AND a.attempt_number=8 AND a.error_code='WEBHOOK_LEASE_EXPIRED'")->fetchColumn(), 'expired lease attempt evidence');
    $repository->purgeExpiredDeliveryData(new DateTimeImmutable('2031-01-01T00:00:00Z'), new DateTimeImmutable('2031-01-01T00:00:00Z'));
    same(0, (int) $pdo->query("SELECT COUNT(*) FROM pa_integration_webhook_delivery WHERE delivery_key='" . $expiredKey . "'")->fetchColumn(), 'terminal expired lease row purged');

    $sessions = new SessionSecurityService($repository);
    $listed = $sessions->list(operation('session-read', 101, 301, 501, $session1));
    same(1, count($listed), 'sessions self scoped');
    same('203.0.113.*', $listed[0]->maskedIp, 'IP masked');
    try {
        $sessions->revoke(operation('session-revoke', 101, 301, 501, $session1), $sessionOther);
        throw new RuntimeException('cross-account session revoked');
    } catch (IntegrationSecurityException $exception) {
        same('SESSION_DEVICE_NOT_FOUND', $exception->problemCode, 'cross account hidden');
    }
    same('revoked', $sessions->revoke(operation('session-revoke', 101, 301, 501, $session1), $session1)->status, 'own session revoked');
    same('revoked', (string) $pdo->query("SELECT status FROM pa_tenant_session_token WHERE token_hash='" . hash('sha256', 'token-' . $session1) . "'")->fetchColumn(), 'session tokens revoked');
    $audit = json_encode($pdo->query('SELECT event_key,target_key_hash,metadata_json,request_id_hash FROM pa_integration_security_event')->fetchAll(), JSON_THROW_ON_ERROR);
    truth(!str_contains($audit, $machine->token) && !str_contains($audit, $endpoint->signingSecret) && !str_contains($audit, $session1), 'audit redacted');

    echo "integration-security mysql harness: PASS\n";
} finally {
    foreach ($drop as $table) {
        try {
            dropTable($pdo, $table);
        } catch (Throwable) {
        }
    }
    try {
        $selected = guardedDatabase($pdo);
        $pdo->exec("DROP DATABASE `{$selected}`");
    } catch (Throwable) {
    }
}
