<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityPage;
use PeanutAdmin\IntegrationSecurity\Application\MachineIdentity;
use PeanutAdmin\IntegrationSecurity\Application\SessionDevice;
use PeanutAdmin\IntegrationSecurity\Application\WebhookAttemptRecord;
use PeanutAdmin\IntegrationSecurity\Application\WebhookDeliveryRecord;
use PeanutAdmin\IntegrationSecurity\Application\WebhookEndpoint;
use PeanutAdmin\IntegrationSecurity\Webhook\TrustedWebhookEvent;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookDelivery;
use PeanutAdmin\Kernel\Auth\TenantContext;
use Throwable;

final readonly class PdoIntegrationSecurityRepository implements IntegrationSecurityRepository
{
    public function __construct(private PDO $pdo) {}

    public function transaction(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $operation();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function createMachine(TenantContext $context, string $identityKey, string $familyKey, string $name, array $scopes, string $tokenPrefix, string $tokenDigest, string $tokenLastFour, ?DateTimeImmutable $expiresAt): MachineIdentity
    {
        return $this->transaction(function () use ($context, $identityKey, $familyKey, $name, $scopes, $tokenPrefix, $tokenDigest, $tokenLastFour, $expiresAt): MachineIdentity {
            $now = new DateTimeImmutable('now');
            $this->execute(<<<'SQL'
INSERT INTO pa_integration_machine_identity (
 tenant_id, identity_key, family_key, name, scopes_json, token_prefix, token_digest,
 token_last_four, expires_at, created_by_member_id, created_at, updated_at
) VALUES (
 :tenant_id, :identity_key, :family_key, :name, :scopes_json, :token_prefix, :token_digest,
 :token_last_four, :expires_at, :member_id, :created_at, :updated_at
)
SQL, [
                'tenant_id' => $context->tenantId, 'identity_key' => $identityKey, 'family_key' => $familyKey,
                'name' => $name, 'scopes_json' => $this->json($scopes), 'token_prefix' => $tokenPrefix,
                'token_digest' => $tokenDigest, 'token_last_four' => $tokenLastFour,
                'expires_at' => $expiresAt === null ? null : $this->format($expiresAt),
                'member_id' => $context->memberId, 'created_at' => $this->format($now), 'updated_at' => $this->format($now),
            ]);
            $this->audit($context, 'tenant.integration.machine_created', 'machine', $identityKey, ['scope_count' => count($scopes)]);
            return $this->machineRow($this->machineByKey($context->tenantId, $identityKey, false) ?? throw IntegrationSecurityException::machineNotFound());
        });
    }

    public function machines(int $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pa_integration_machine_identity WHERE tenant_id = :tenant_id ORDER BY id DESC');
        $statement->execute(['tenant_id' => $tenantId]);
        return array_map($this->machineRow(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function machineByDigest(string $tokenDigest): ?array
    {
        $statement = $this->pdo->prepare('SELECT tenant_id, identity_key, scopes_json, status, expires_at FROM pa_integration_machine_identity WHERE token_digest = :digest');
        $statement->execute(['digest' => $tokenDigest]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'tenant_id' => (int) $row['tenant_id'], 'identity_key' => (string) $row['identity_key'],
            'scopes' => $this->stringList($row['scopes_json']), 'status' => (string) $row['status'],
            'expires_at' => $row['expires_at'] === null ? null : $this->instant((string) $row['expires_at']),
        ];
    }

    public function touchMachine(string $tokenDigest, DateTimeImmutable $now): void
    {
        $this->execute("UPDATE pa_integration_machine_identity SET last_used_at = :last_used_at, updated_at = :updated_at WHERE token_digest = :digest AND status = 'active'", [
            'last_used_at' => $this->format($now), 'updated_at' => $this->format($now), 'digest' => $tokenDigest,
        ]);
    }

    public function rotateMachine(TenantContext $context, string $identityKey, int $expectedRevision, string $successorKey, string $name, array $scopes, string $tokenPrefix, string $tokenDigest, string $tokenLastFour, ?DateTimeImmutable $expiresAt): MachineIdentity
    {
        return $this->transaction(function () use ($context, $identityKey, $expectedRevision, $successorKey, $name, $scopes, $tokenPrefix, $tokenDigest, $tokenLastFour, $expiresAt): MachineIdentity {
            $current = $this->machineByKey($context->tenantId, $identityKey, true);
            if ($current === null) {
                throw IntegrationSecurityException::machineNotFound();
            }
            if ($current['status'] !== 'active' || (int) $current['revision'] !== $expectedRevision) {
                throw IntegrationSecurityException::conflict();
            }
            $now = new DateTimeImmutable('now');
            $updated = $this->execute("UPDATE pa_integration_machine_identity SET status = 'rotated', rotated_at = :rotated_at, revision = revision + 1, updated_at = :updated_at WHERE id = :id AND status = 'active' AND revision = :revision", [
                'rotated_at' => $this->format($now), 'updated_at' => $this->format($now), 'id' => $current['id'], 'revision' => $expectedRevision,
            ]);
            if ($updated !== 1) {
                throw IntegrationSecurityException::conflict();
            }
            $this->execute(<<<'SQL'
INSERT INTO pa_integration_machine_identity (
 tenant_id, identity_key, family_key, name, scopes_json, token_prefix, token_digest,
 token_last_four, expires_at, created_by_member_id, created_at, updated_at
) VALUES (:tenant_id, :identity_key, :family_key, :name, :scopes_json, :token_prefix,
 :token_digest, :token_last_four, :expires_at, :member_id, :created_at, :updated_at)
SQL, [
                'tenant_id' => $context->tenantId, 'identity_key' => $successorKey, 'family_key' => $current['family_key'],
                'name' => $name, 'scopes_json' => $this->json($scopes), 'token_prefix' => $tokenPrefix,
                'token_digest' => $tokenDigest, 'token_last_four' => $tokenLastFour,
                'expires_at' => $expiresAt === null ? null : $this->format($expiresAt), 'member_id' => $context->memberId,
                'created_at' => $this->format($now), 'updated_at' => $this->format($now),
            ]);
            $this->audit($context, 'tenant.integration.machine_rotated', 'machine', $identityKey, ['successor_hash' => hash('sha256', $successorKey)]);
            return $this->machineRow($this->machineByKey($context->tenantId, $successorKey, false) ?? throw IntegrationSecurityException::machineNotFound());
        });
    }

    public function revokeMachine(TenantContext $context, string $identityKey, int $expectedRevision): MachineIdentity
    {
        return $this->transaction(function () use ($context, $identityKey, $expectedRevision): MachineIdentity {
            $row = $this->machineByKey($context->tenantId, $identityKey, true);
            if ($row === null) {
                throw IntegrationSecurityException::machineNotFound();
            }
            if ($row['status'] !== 'active' || (int) $row['revision'] !== $expectedRevision) {
                throw IntegrationSecurityException::conflict();
            }
            $now = new DateTimeImmutable('now');
            if ($this->execute("UPDATE pa_integration_machine_identity SET status = 'revoked', revoked_at = :revoked_at, revision = revision + 1, updated_at = :updated_at WHERE id = :id AND status = 'active' AND revision = :revision", [
                'revoked_at' => $this->format($now), 'updated_at' => $this->format($now), 'id' => $row['id'], 'revision' => $expectedRevision,
            ]) !== 1) {
                throw IntegrationSecurityException::conflict();
            }
            $this->audit($context, 'tenant.integration.machine_revoked', 'machine', $identityKey, []);
            return $this->machineRow($this->machineByKey($context->tenantId, $identityKey, false) ?? throw IntegrationSecurityException::machineNotFound());
        });
    }

    public function createEndpoint(TenantContext $context, string $endpointKey, string $name, string $url, array $events, string $secretCiphertext, string $secretKeyId): WebhookEndpoint
    {
        return $this->transaction(function () use ($context, $endpointKey, $name, $url, $events, $secretCiphertext, $secretKeyId): WebhookEndpoint {
            $now = new DateTimeImmutable('now');
            $this->execute(<<<'SQL'
INSERT INTO pa_integration_webhook_endpoint (
 tenant_id, endpoint_key, name, url, events_json, secret_ciphertext, secret_key_id,
 created_by_member_id, created_at, updated_at
) VALUES (:tenant_id, :endpoint_key, :name, :url, :events_json, :ciphertext, :key_id, :member_id, :created_at, :updated_at)
SQL, [
                'tenant_id' => $context->tenantId, 'endpoint_key' => $endpointKey, 'name' => $name, 'url' => $url,
                'events_json' => $this->json($events), 'ciphertext' => $secretCiphertext, 'key_id' => $secretKeyId,
                'member_id' => $context->memberId, 'created_at' => $this->format($now), 'updated_at' => $this->format($now),
            ]);
            $this->audit($context, 'tenant.integration.webhook_created', 'webhook', $endpointKey, ['event_count' => count($events)]);
            return $this->endpointRow($this->endpointByKey($context->tenantId, $endpointKey, false) ?? throw IntegrationSecurityException::endpointNotFound());
        });
    }

    public function endpoints(int $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pa_integration_webhook_endpoint WHERE tenant_id = :tenant_id ORDER BY id DESC');
        $statement->execute(['tenant_id' => $tenantId]);
        return array_map($this->endpointRow(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function rotateEndpointSecret(TenantContext $context, string $endpointKey, int $expectedRevision, string $secretCiphertext, string $secretKeyId): WebhookEndpoint
    {
        return $this->transaction(function () use ($context, $endpointKey, $expectedRevision, $secretCiphertext, $secretKeyId): WebhookEndpoint {
            $row = $this->endpointByKey($context->tenantId, $endpointKey, true);
            if ($row === null) {
                throw IntegrationSecurityException::endpointNotFound();
            }
            if ($row['status'] !== 'active' || (int) $row['revision'] !== $expectedRevision) {
                throw IntegrationSecurityException::conflict();
            }
            $now = new DateTimeImmutable('now');
            if ($this->execute("UPDATE pa_integration_webhook_endpoint SET secret_ciphertext = :ciphertext, secret_key_id = :key_id, revision = revision + 1, updated_at = :now WHERE id = :id AND status = 'active' AND revision = :revision", [
                'ciphertext' => $secretCiphertext, 'key_id' => $secretKeyId, 'now' => $this->format($now),
                'id' => $row['id'], 'revision' => $expectedRevision,
            ]) !== 1) {
                throw IntegrationSecurityException::conflict();
            }
            $this->audit($context, 'tenant.integration.webhook_secret_rotated', 'webhook', $endpointKey, []);
            return $this->endpointRow($this->endpointByKey($context->tenantId, $endpointKey, false) ?? throw IntegrationSecurityException::endpointNotFound());
        });
    }

    public function disableEndpoint(TenantContext $context, string $endpointKey, int $expectedRevision): WebhookEndpoint
    {
        return $this->transaction(function () use ($context, $endpointKey, $expectedRevision): WebhookEndpoint {
            $row = $this->endpointByKey($context->tenantId, $endpointKey, true);
            if ($row === null) {
                throw IntegrationSecurityException::endpointNotFound();
            }
            if ($row['status'] !== 'active' || (int) $row['revision'] !== $expectedRevision) {
                throw IntegrationSecurityException::conflict();
            }
            $now = new DateTimeImmutable('now');
            if ($this->execute("UPDATE pa_integration_webhook_endpoint SET status = 'disabled', disabled_at = :disabled_at, revision = revision + 1, updated_at = :updated_at WHERE id = :id AND status = 'active' AND revision = :revision", [
                'disabled_at' => $this->format($now), 'updated_at' => $this->format($now), 'id' => $row['id'], 'revision' => $expectedRevision,
            ]) !== 1) {
                throw IntegrationSecurityException::conflict();
            }
            $this->execute("UPDATE pa_integration_webhook_delivery SET status = 'permanent_failed', last_error_code = 'WEBHOOK_ENDPOINT_DISABLED', updated_at = :now WHERE tenant_id = :tenant_id AND endpoint_id = :endpoint_id AND status IN ('pending', 'retryable')", [
                'now' => $this->format($now), 'tenant_id' => $context->tenantId, 'endpoint_id' => $row['id'],
            ]);
            $this->audit($context, 'tenant.integration.webhook_disabled', 'webhook', $endpointKey, []);
            return $this->endpointRow($this->endpointByKey($context->tenantId, $endpointKey, false) ?? throw IntegrationSecurityException::endpointNotFound());
        });
    }

    public function activeEndpointKeysForEvent(int $tenantId, string $eventType): array
    {
        $statement = $this->pdo->prepare("SELECT endpoint_key FROM pa_integration_webhook_endpoint WHERE tenant_id = :tenant_id AND status = 'active' AND JSON_CONTAINS(events_json, :event_json) ORDER BY id");
        $statement->execute(['tenant_id' => $tenantId, 'event_json' => $this->json($eventType)]);
        return array_map(static fn(array $row): array => ['endpoint_key' => (string) $row['endpoint_key']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function enqueueDelivery(int $tenantId, string $endpointKey, TrustedWebhookEvent $event, DateTimeImmutable $now): string
    {
        $endpoint = $this->endpointByKey($tenantId, $endpointKey, true);
        if ($endpoint === null || $endpoint['status'] !== 'active') {
            throw IntegrationSecurityException::endpointNotFound();
        }
        $payload = $event->canonicalPayload();
        $digest = hash('sha256', $payload);
        $existing = $this->fetchOne('SELECT delivery_key, event_type, payload_sha256 FROM pa_integration_webhook_delivery WHERE tenant_id = :tenant_id AND endpoint_id = :endpoint_id AND event_key = :event_key FOR UPDATE', [
            'tenant_id' => $tenantId, 'endpoint_id' => $endpoint['id'], 'event_key' => $event->eventKey,
        ]);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['event_type'], $event->eventType) || !hash_equals((string) $existing['payload_sha256'], $digest)) {
                throw IntegrationSecurityException::conflict();
            }
            return (string) $existing['delivery_key'];
        }
        $deliveryKey = 'delivery_' . bin2hex(random_bytes(16));
        $this->execute(<<<'SQL'
INSERT INTO pa_integration_webhook_delivery (
 tenant_id, endpoint_id, delivery_key, event_key, event_type, payload_json,
 payload_sha256, available_at, payload_expires_at, created_at, updated_at
) VALUES (:tenant_id, :endpoint_id, :delivery_key, :event_key, :event_type, :payload_json,
 :payload_sha256, :available_at, :payload_expires_at, :created_at, :updated_at)
SQL, [
            'tenant_id' => $tenantId, 'endpoint_id' => $endpoint['id'], 'delivery_key' => $deliveryKey,
            'event_key' => $event->eventKey, 'event_type' => $event->eventType, 'payload_json' => $payload,
            'payload_sha256' => $digest, 'available_at' => $this->format($now), 'payload_expires_at' => $this->format($now->modify('+7 days')),
            'created_at' => $this->format($now), 'updated_at' => $this->format($now),
        ]);
        return $deliveryKey;
    }

    public function claimDelivery(int $tenantId, string $leaseDigest, int $leaseSeconds, DateTimeImmutable $now): ?WebhookDelivery
    {
        if ($leaseSeconds < 5 || $leaseSeconds > 300 || preg_match('/^[0-9a-f]{64}$/D', $leaseDigest) !== 1) {
            throw IntegrationSecurityException::invalid();
        }
        return $this->transaction(function () use ($tenantId, $leaseDigest, $leaseSeconds, $now): ?WebhookDelivery {
            $expired = $this->fetchAll('SELECT id, attempt_count FROM pa_integration_webhook_delivery WHERE tenant_id = :tenant_id AND status = \'delivering\' AND lease_expires_at <= :lease_cutoff ORDER BY id FOR UPDATE', [
                'tenant_id' => $tenantId, 'lease_cutoff' => $this->format($now),
            ]);
            foreach ($expired as $lease) {
                $attempt = (int) $lease['attempt_count'];
                $status = $attempt < 8 ? 'retryable' : 'permanent_failed';
                $this->execute('INSERT INTO pa_integration_webhook_attempt (tenant_id, delivery_id, attempt_number, outcome, response_status, error_code, duration_ms, attempted_at) VALUES (:tenant_id, :delivery_id, :attempt, :outcome, NULL, \'WEBHOOK_LEASE_EXPIRED\', 0, :now)', [
                    'tenant_id' => $tenantId, 'delivery_id' => $lease['id'], 'attempt' => $attempt,
                    'outcome' => $status, 'now' => $this->format($now),
                ]);
                $this->execute('UPDATE pa_integration_webhook_delivery SET status = :status, lease_digest = NULL, lease_expires_at = NULL, last_error_code = \'WEBHOOK_LEASE_EXPIRED\', available_at = :available_at, updated_at = :updated_at WHERE tenant_id = :tenant_id AND id = :id AND status = \'delivering\'', [
                    'status' => $status, 'available_at' => $this->format($now), 'updated_at' => $this->format($now),
                    'tenant_id' => $tenantId, 'id' => $lease['id'],
                ]);
            }
            $row = $this->fetchOne(<<<'SQL'
SELECT d.*, e.endpoint_key, e.url, e.secret_ciphertext, e.secret_key_id
FROM pa_integration_webhook_delivery d
JOIN pa_integration_webhook_endpoint e ON e.tenant_id = d.tenant_id AND e.id = d.endpoint_id
WHERE d.tenant_id = :tenant_id AND d.status IN ('pending', 'retryable')
  AND d.available_at <= :now AND d.attempt_count < 8 AND d.payload_json IS NOT NULL
  AND e.status = 'active'
ORDER BY d.available_at, d.id
LIMIT 1 FOR UPDATE SKIP LOCKED
SQL, ['tenant_id' => $tenantId, 'now' => $this->format($now)]);
            if ($row === null) {
                return null;
            }
            $attempt = (int) $row['attempt_count'] + 1;
            $expires = $now->modify('+' . $leaseSeconds . ' seconds');
            if ($this->execute("UPDATE pa_integration_webhook_delivery SET status = 'delivering', attempt_count = :attempt, lease_digest = :lease_digest, lease_expires_at = :lease_expires_at, updated_at = :now WHERE id = :id AND tenant_id = :tenant_id AND status IN ('pending', 'retryable')", [
                'attempt' => $attempt, 'lease_digest' => $leaseDigest, 'lease_expires_at' => $this->format($expires),
                'now' => $this->format($now), 'id' => $row['id'], 'tenant_id' => $tenantId,
            ]) !== 1) {
                throw IntegrationSecurityException::conflict();
            }
            return new WebhookDelivery(
                (int) $row['id'],
                $tenantId,
                (string) $row['endpoint_key'],
                (string) $row['delivery_key'],
                (string) $row['event_type'],
                $this->jsonText($row['payload_json']),
                (string) $row['payload_sha256'],
                (string) $row['url'],
                (string) $row['secret_ciphertext'],
                (string) $row['secret_key_id'],
                $attempt,
                $leaseDigest,
            );
        });
    }

    public function completeDelivery(WebhookDelivery $delivery, int $statusCode, int $durationMs, DateTimeImmutable $now): void
    {
        $this->finishDelivery($delivery, 'delivered', null, $statusCode, $durationMs, $now, false);
    }

    public function failDelivery(WebhookDelivery $delivery, string $safeCode, bool $retryable, ?int $statusCode, int $durationMs, DateTimeImmutable $now): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $safeCode) !== 1) {
            throw IntegrationSecurityException::invalid();
        }
        $status = $retryable && $delivery->attemptNumber < 8 ? 'retryable' : 'permanent_failed';
        $this->finishDelivery($delivery, $status, $safeCode, $statusCode, $durationMs, $now, $status === 'retryable');
    }

    private function finishDelivery(WebhookDelivery $delivery, string $status, ?string $errorCode, ?int $statusCode, int $durationMs, DateTimeImmutable $now, bool $retryable): void
    {
        if ($durationMs < 0 || $durationMs > 30000 || ($statusCode !== null && ($statusCode < 100 || $statusCode > 599))) {
            throw IntegrationSecurityException::invalid();
        }
        $this->transaction(function () use ($delivery, $status, $errorCode, $statusCode, $durationMs, $now, $retryable): void {
            $current = $this->fetchOne('SELECT status, attempt_count, lease_digest FROM pa_integration_webhook_delivery WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE', [
                'tenant_id' => $delivery->tenantId, 'id' => $delivery->id,
            ]);
            if ($current === null || $current['status'] !== 'delivering' || (int) $current['attempt_count'] !== $delivery->attemptNumber || !hash_equals((string) $current['lease_digest'], $delivery->leaseDigest)) {
                throw IntegrationSecurityException::conflict();
            }
            $this->execute('INSERT INTO pa_integration_webhook_attempt (tenant_id, delivery_id, attempt_number, outcome, response_status, error_code, duration_ms, attempted_at) VALUES (:tenant_id, :delivery_id, :attempt, :outcome, :response_status, :error_code, :duration_ms, :now)', [
                'tenant_id' => $delivery->tenantId, 'delivery_id' => $delivery->id, 'attempt' => $delivery->attemptNumber,
                'outcome' => $status, 'response_status' => $statusCode, 'error_code' => $errorCode,
                'duration_ms' => $durationMs, 'now' => $this->format($now),
            ]);
            $available = $retryable ? $now->modify('+' . min(300, 5 * (2 ** max(0, $delivery->attemptNumber - 1))) . ' seconds') : $now;
            $this->execute('UPDATE pa_integration_webhook_delivery SET status = :status, lease_digest = NULL, lease_expires_at = NULL, last_status_code = :response_status, last_error_code = :error_code, delivered_at = :delivered_at, available_at = :available_at, updated_at = :now WHERE tenant_id = :tenant_id AND id = :id', [
                'status' => $status, 'response_status' => $statusCode, 'error_code' => $errorCode,
                'delivered_at' => $status === 'delivered' ? $this->format($now) : null,
                'available_at' => $this->format($available), 'now' => $this->format($now),
                'tenant_id' => $delivery->tenantId, 'id' => $delivery->id,
            ]);
        });
    }

    public function purgeExpiredDeliveryData(DateTimeImmutable $payloadCutoff, DateTimeImmutable $evidenceCutoff): array
    {
        return $this->transaction(function () use ($payloadCutoff, $evidenceCutoff): array {
            $payloads = $this->execute("UPDATE pa_integration_webhook_delivery SET payload_json = NULL, updated_at = updated_at WHERE payload_json IS NOT NULL AND payload_expires_at <= :cutoff AND status IN ('delivered', 'permanent_failed')", ['cutoff' => $this->format($payloadCutoff)]);
            $attempts = $this->execute("DELETE a FROM pa_integration_webhook_attempt a JOIN pa_integration_webhook_delivery d ON d.tenant_id = a.tenant_id AND d.id = a.delivery_id WHERE d.status IN ('delivered', 'permanent_failed') AND d.updated_at <= :cutoff", ['cutoff' => $this->format($evidenceCutoff)]);
            $deliveries = $this->execute("DELETE FROM pa_integration_webhook_delivery WHERE status IN ('delivered', 'permanent_failed') AND updated_at <= :cutoff", ['cutoff' => $this->format($evidenceCutoff)]);
            return ['payloads_cleared' => $payloads, 'attempts_deleted' => $attempts, 'deliveries_deleted' => $deliveries];
        });
    }

    public function deliveryRecords(int $tenantId, int $page, int $pageSize): IntegrationSecurityPage
    {
        $offset = ($page - 1) * $pageSize;
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM pa_integration_webhook_delivery WHERE tenant_id = :tenant_id');
        $count->execute(['tenant_id' => $tenantId]);
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT d.delivery_key, e.endpoint_key, d.event_type, d.status, d.attempt_count,
       d.last_status_code, d.last_error_code, d.created_at, d.updated_at, d.delivered_at
FROM pa_integration_webhook_delivery d
JOIN pa_integration_webhook_endpoint e ON e.tenant_id = d.tenant_id AND e.id = d.endpoint_id
WHERE d.tenant_id = :tenant_id
ORDER BY d.created_at DESC, d.id DESC
LIMIT :page_size OFFSET :offset
SQL);
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $items = array_map(fn(array $row): WebhookDeliveryRecord => new WebhookDeliveryRecord(
            (string) $row['delivery_key'],
            (string) $row['endpoint_key'],
            (string) $row['event_type'],
            (string) $row['status'],
            (int) $row['attempt_count'],
            $row['last_status_code'] === null ? null : (int) $row['last_status_code'],
            $row['last_error_code'] === null ? null : (string) $row['last_error_code'],
            $this->instant((string) $row['created_at']),
            $this->instant((string) $row['updated_at']),
            $row['delivered_at'] === null ? null : $this->instant((string) $row['delivered_at']),
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
        return new IntegrationSecurityPage($items, $page, $pageSize, (int) $count->fetchColumn());
    }

    public function deliveryAttemptRecords(int $tenantId, string $deliveryKey, int $page, int $pageSize): IntegrationSecurityPage
    {
        $delivery = $this->fetchOne('SELECT id FROM pa_integration_webhook_delivery WHERE tenant_id = :tenant_id AND delivery_key = :delivery_key', [
            'tenant_id' => $tenantId, 'delivery_key' => $deliveryKey,
        ]);
        if ($delivery === null) {
            return new IntegrationSecurityPage([], $page, $pageSize, 0);
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM pa_integration_webhook_attempt WHERE tenant_id = :tenant_id AND delivery_id = :delivery_id');
        $count->execute(['tenant_id' => $tenantId, 'delivery_id' => $delivery['id']]);
        $statement = $this->pdo->prepare('SELECT attempt_number, outcome, response_status, error_code, duration_ms, attempted_at FROM pa_integration_webhook_attempt WHERE tenant_id = :tenant_id AND delivery_id = :delivery_id ORDER BY attempt_number DESC LIMIT :page_size OFFSET :offset');
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':delivery_id', (int) $delivery['id'], PDO::PARAM_INT);
        $statement->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $statement->execute();
        $items = array_map(fn(array $row): WebhookAttemptRecord => new WebhookAttemptRecord(
            (int) $row['attempt_number'],
            (string) $row['outcome'],
            $row['response_status'] === null ? null : (int) $row['response_status'],
            $row['error_code'] === null ? null : (string) $row['error_code'],
            (int) $row['duration_ms'],
            $this->instant((string) $row['attempted_at']),
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
        return new IntegrationSecurityPage($items, $page, $pageSize, (int) $count->fetchColumn());
    }

    public function sessionDevices(int $tenantId, int $accountId, string $currentSessionKey): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pa_tenant_session WHERE tenant_id = :tenant_id AND account_id = :account_id ORDER BY last_seen_at DESC, id DESC');
        $statement->execute(['tenant_id' => $tenantId, 'account_id' => $accountId]);
        return array_map(fn(array $row): SessionDevice => $this->sessionRow($row, $currentSessionKey), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function revokeOwnSession(TenantContext $context, string $sessionKey): SessionDevice
    {
        return $this->transaction(function () use ($context, $sessionKey): SessionDevice {
            $row = $this->fetchOne('SELECT * FROM pa_tenant_session WHERE tenant_id = :tenant_id AND account_id = :account_id AND session_key = :session_key FOR UPDATE', [
                'tenant_id' => $context->tenantId, 'account_id' => $context->accountId, 'session_key' => $sessionKey,
            ]);
            if ($row === null) {
                throw IntegrationSecurityException::sessionNotFound();
            }
            if ($row['status'] === 'active') {
                $now = new DateTimeImmutable('now');
                $this->execute("UPDATE pa_tenant_session SET status = 'revoked', revoked_at = :revoked_at, revoke_reason = 'user_device_revoked', updated_at = :updated_at WHERE id = :id AND status = 'active'", [
                    'revoked_at' => $this->format($now), 'updated_at' => $this->format($now), 'id' => $row['id'],
                ]);
                $this->execute("UPDATE pa_tenant_session_token SET status = 'revoked', revoked_at = :now WHERE session_id = :id AND status = 'active'", [
                    'now' => $this->format($now), 'id' => $row['id'],
                ]);
                $this->audit($context, 'tenant.integration.session_revoked', 'session', $sessionKey, ['current' => hash_equals($context->sessionKey, $sessionKey)]);
            }
            $updated = $this->fetchOne('SELECT * FROM pa_tenant_session WHERE id = :id', ['id' => $row['id']]) ?? throw IntegrationSecurityException::sessionNotFound();
            return $this->sessionRow($updated, $context->sessionKey);
        });
    }

    private function machineByKey(int $tenantId, string $identityKey, bool $lock): ?array
    {
        return $this->fetchOne('SELECT * FROM pa_integration_machine_identity WHERE tenant_id = :tenant_id AND identity_key = :key' . ($lock ? ' FOR UPDATE' : ''), ['tenant_id' => $tenantId, 'key' => $identityKey]);
    }

    private function endpointByKey(int $tenantId, string $endpointKey, bool $lock): ?array
    {
        return $this->fetchOne('SELECT * FROM pa_integration_webhook_endpoint WHERE tenant_id = :tenant_id AND endpoint_key = :key' . ($lock ? ' FOR UPDATE' : ''), ['tenant_id' => $tenantId, 'key' => $endpointKey]);
    }

    private function machineRow(array $row): MachineIdentity
    {
        return new MachineIdentity(
            (string) $row['identity_key'],
            (string) $row['name'],
            $this->stringList($row['scopes_json']),
            (string) $row['status'],
            (string) $row['token_prefix'],
            (string) $row['token_last_four'],
            $row['expires_at'] === null ? null : $this->instant((string) $row['expires_at']),
            $row['last_used_at'] === null ? null : $this->instant((string) $row['last_used_at']),
            (int) $row['revision'],
            $this->instant((string) $row['created_at']),
        );
    }

    private function endpointRow(array $row): WebhookEndpoint
    {
        return new WebhookEndpoint(
            (string) $row['endpoint_key'],
            (string) $row['name'],
            (string) $row['url'],
            $this->stringList($row['events_json']),
            (string) $row['status'],
            (int) $row['revision'],
            $this->instant((string) $row['created_at']),
        );
    }

    private function sessionRow(array $row, string $currentSessionKey): SessionDevice
    {
        $ip = is_string($row['ip_address']) ? $this->maskIp($row['ip_address']) : null;
        $agent = is_string($row['user_agent_hash']) ? substr($row['user_agent_hash'], 0, 12) : null;
        return new SessionDevice(
            (string) $row['session_key'],
            (string) $row['client_key'],
            (string) $row['status'],
            hash_equals($currentSessionKey, (string) $row['session_key']),
            $ip,
            $agent,
            $this->instant((string) $row['issued_at']),
            $this->instant((string) $row['last_seen_at']),
            $this->instant((string) $row['absolute_expires_at']),
            $row['revoked_at'] === null ? null : $this->instant((string) $row['revoked_at']),
        );
    }

    private function maskIp(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '*';
            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 3)) . ':*';
        }
        return null;
    }

    /** @param array<string, scalar|null> $metadata */
    private function audit(TenantContext $context, string $eventKey, string $targetType, string $targetKey, array $metadata): void
    {
        if (count($metadata) > 8) {
            throw IntegrationSecurityException::invalid();
        }
        $this->execute('INSERT INTO pa_integration_security_event (tenant_id, event_key, actor_member_id, target_type, target_key_hash, metadata_json, request_id_hash, occurred_at) VALUES (:tenant_id, :event_key, :member_id, :target_type, :target_hash, :metadata, :request_hash, :now)', [
            'tenant_id' => $context->tenantId, 'event_key' => $eventKey, 'member_id' => $context->memberId,
            'target_type' => $targetType, 'target_hash' => hash('sha256', $targetKey), 'metadata' => $this->json($metadata),
            'request_hash' => hash('sha256', $context->requestId), 'now' => $this->format(new DateTimeImmutable('now')),
        ]);
    }

    /** @return list<string> */
    private function stringList(mixed $json): array
    {
        $decoded = json_decode($this->jsonText($json), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw IntegrationSecurityException::invalid();
        }
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                throw IntegrationSecurityException::invalid();
            }
        }
        return $decoded;
    }

    private function jsonText(mixed $json): string
    {
        if (!is_string($json)) {
            throw IntegrationSecurityException::invalid();
        }
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return $json;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
    private function instant(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** @param array<string, mixed> $parameters */
    private function execute(string $sql, array $parameters): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string, mixed> $parameters @return array<string, mixed>|null */
    private function fetchOne(string $sql, array $parameters): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $parameters @return list<array<string, mixed>> */
    private function fetchAll(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
