<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Persistence;

use DateTimeImmutable;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityPage;
use PeanutAdmin\IntegrationSecurity\Application\MachineIdentity;
use PeanutAdmin\IntegrationSecurity\Application\SessionDevice;
use PeanutAdmin\IntegrationSecurity\Application\WebhookEndpoint;
use PeanutAdmin\IntegrationSecurity\Webhook\TrustedWebhookEvent;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookDelivery;
use PeanutAdmin\Kernel\Auth\TenantContext;

interface IntegrationSecurityRepository
{
    public function transaction(callable $operation): mixed;

    /** @param list<string> $scopes */
    public function createMachine(TenantContext $context, string $identityKey, string $familyKey, string $name, array $scopes, string $tokenPrefix, string $tokenDigest, string $tokenLastFour, ?DateTimeImmutable $expiresAt): MachineIdentity;
    /** @return list<MachineIdentity> */
    public function machines(int $tenantId): array;
    /** @return array{tenant_id:int, identity_key:string, scopes:list<string>, status:string, expires_at:?string}|null */
    public function machineByDigest(string $tokenDigest): ?array;
    public function touchMachine(string $tokenDigest, DateTimeImmutable $now): void;
    /** @param list<string> $scopes */
    public function rotateMachine(TenantContext $context, string $identityKey, int $expectedRevision, string $successorKey, string $name, array $scopes, string $tokenPrefix, string $tokenDigest, string $tokenLastFour, ?DateTimeImmutable $expiresAt): MachineIdentity;
    public function revokeMachine(TenantContext $context, string $identityKey, int $expectedRevision): MachineIdentity;

    /** @param list<string> $events */
    public function createEndpoint(TenantContext $context, string $endpointKey, string $name, string $url, array $events, string $secretCiphertext, string $secretKeyId): WebhookEndpoint;
    /** @return list<WebhookEndpoint> */
    public function endpoints(int $tenantId): array;
    public function rotateEndpointSecret(TenantContext $context, string $endpointKey, int $expectedRevision, string $secretCiphertext, string $secretKeyId): WebhookEndpoint;
    public function disableEndpoint(TenantContext $context, string $endpointKey, int $expectedRevision): WebhookEndpoint;
    /** @return list<array{endpoint_key:string}> */
    public function activeEndpointKeysForEvent(int $tenantId, string $eventType): array;
    public function enqueueDelivery(int $tenantId, string $endpointKey, TrustedWebhookEvent $event, DateTimeImmutable $now): string;
    public function claimDelivery(int $tenantId, string $leaseDigest, int $leaseSeconds, DateTimeImmutable $now): ?WebhookDelivery;
    public function completeDelivery(WebhookDelivery $delivery, int $statusCode, int $durationMs, DateTimeImmutable $now): void;
    public function failDelivery(WebhookDelivery $delivery, string $safeCode, bool $retryable, ?int $statusCode, int $durationMs, DateTimeImmutable $now): void;
    public function purgeExpiredDeliveryData(DateTimeImmutable $payloadCutoff, DateTimeImmutable $evidenceCutoff): array;
    public function deliveryRecords(int $tenantId, int $page, int $pageSize): IntegrationSecurityPage;
    public function deliveryAttemptRecords(int $tenantId, string $deliveryKey, int $page, int $pageSize): IntegrationSecurityPage;

    /** @return list<SessionDevice> */
    public function sessionDevices(int $tenantId, int $accountId, string $currentSessionKey): array;
    public function revokeOwnSession(TenantContext $context, string $sessionKey): SessionDevice;
}
