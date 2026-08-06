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
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityPage;
use PeanutAdmin\IntegrationSecurity\Application\MachineIdentity;
use PeanutAdmin\IntegrationSecurity\Application\MachineIdentityService;
use PeanutAdmin\IntegrationSecurity\Application\MachineScopeCatalog;
use PeanutAdmin\IntegrationSecurity\Application\MachineScopeGrantPolicy;
use PeanutAdmin\IntegrationSecurity\Application\MachineScopeGrantResolver;
use PeanutAdmin\IntegrationSecurity\Application\SessionDevice;
use PeanutAdmin\IntegrationSecurity\Application\SessionSecurityService;
use PeanutAdmin\IntegrationSecurity\Application\WebhookEndpoint;
use PeanutAdmin\IntegrationSecurity\Application\WebhookService;
use PeanutAdmin\IntegrationSecurity\Crypto\AesGcmWebhookSecretProtector;
use PeanutAdmin\IntegrationSecurity\Package;
use PeanutAdmin\IntegrationSecurity\Persistence\IntegrationSecurityRepository;
use PeanutAdmin\IntegrationSecurity\Webhook\HostAddressResolver;
use PeanutAdmin\IntegrationSecurity\Webhook\TrustedWebhookEvent;
use PeanutAdmin\IntegrationSecurity\Webhook\TrustedWebhookPublisher;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookDelivery;
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
function expectCode(string $code, callable $operation, string $message): void
{
    try {
        $operation();
    } catch (IntegrationSecurityException $exception) {
        same($code, $exception->problemCode, $message);
        return;
    }
    throw new RuntimeException($message . ' did not fail');
}
function context(string $operation, int $tenantId = 101, int $accountId = 301, int $memberId = 501, string $sessionKey = '01J00000000000000000000000'): AuthorizedOperationContext
{
    $session = new ValidatedTenantSession(1, $sessionKey, $tenantId, $accountId, $memberId, 'admin-web', new DateTimeImmutable('2026-07-24T10:00:00Z'), 1);
    return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
        TenantContext::fromValidatedSession($session, 'req_integration_feature'),
        Package::RESOURCE_KEY,
        $operation,
        [],
        hash('sha256', 'basis'),
    ));
}

final class MemoryRepository implements IntegrationSecurityRepository
{
    public ?MachineIdentity $machine = null;
    public ?array $auth = null;
    public ?WebhookEndpoint $endpoint = null;
    public ?WebhookDelivery $delivery = null;
    public ?array $failure = null;
    public bool $completed = false;
    public int $machineTouches = 0;
    public int $deliveryCount = 0;
    private bool $claimed = false;

    public function transaction(callable $operation): mixed
    {
        return $operation();
    }
    public function createMachine(TenantContext $context, string $identityKey, string $familyKey, string $name, array $scopes, string $tokenPrefix, string $tokenDigest, string $tokenLastFour, ?DateTimeImmutable $expiresAt): MachineIdentity
    {
        $this->machine = new MachineIdentity($identityKey, $name, $scopes, 'active', $tokenPrefix, $tokenLastFour, $expiresAt?->format('Y-m-d\TH:i:s.v\Z'), null, 1, '2026-07-24T10:00:00.000Z');
        $this->auth = ['tenant_id' => $context->tenantId, 'identity_key' => $identityKey, 'scopes' => $scopes, 'status' => 'active', 'expires_at' => $this->machine->expiresAt, 'digest' => $tokenDigest];
        return $this->machine;
    }
    public function machines(int $tenantId): array
    {
        return $this->machine === null || $tenantId !== 101 ? [] : [$this->machine];
    }
    public function machineByDigest(string $tokenDigest): ?array
    {
        return $this->auth !== null && hash_equals($this->auth['digest'], $tokenDigest) ? array_diff_key($this->auth, ['digest' => true]) : null;
    }
    public function touchMachine(string $tokenDigest, DateTimeImmutable $now): void
    {
        ++$this->machineTouches;
    }
    public function rotateMachine(TenantContext $context, string $identityKey, int $expectedRevision, string $successorKey, string $name, array $scopes, string $tokenPrefix, string $tokenDigest, string $tokenLastFour, ?DateTimeImmutable $expiresAt): MachineIdentity
    {
        if ($this->machine === null || $expectedRevision !== $this->machine->revision) {
            throw IntegrationSecurityException::conflict();
        }
        return $this->createMachine($context, $successorKey, str_repeat('f', 32), $name, $scopes, $tokenPrefix, $tokenDigest, $tokenLastFour, $expiresAt);
    }
    public function revokeMachine(TenantContext $context, string $identityKey, int $expectedRevision): MachineIdentity
    {
        if ($this->machine === null || !hash_equals($this->machine->identityKey, $identityKey) || $expectedRevision !== $this->machine->revision) {
            throw IntegrationSecurityException::conflict();
        }
        $this->machine = new MachineIdentity($identityKey, $this->machine->name, $this->machine->scopes, 'revoked', $this->machine->tokenPrefix, $this->machine->tokenLastFour, $this->machine->expiresAt, $this->machine->lastUsedAt, 2, $this->machine->createdAt);
        if ($this->auth !== null) {
            $this->auth['status'] = 'revoked';
        }
        return $this->machine;
    }
    public function createEndpoint(TenantContext $context, string $endpointKey, string $name, string $url, array $events, string $secretCiphertext, string $secretKeyId): WebhookEndpoint
    {
        $this->endpoint = new WebhookEndpoint($endpointKey, $name, $url, $events, 'active', 1, '2026-07-24T10:00:00.000Z');
        return $this->endpoint;
    }
    public function endpoints(int $tenantId): array
    {
        return $this->endpoint === null || $tenantId !== 101 ? [] : [$this->endpoint];
    }
    public function rotateEndpointSecret(TenantContext $context, string $endpointKey, int $expectedRevision, string $secretCiphertext, string $secretKeyId): WebhookEndpoint
    {
        if ($this->endpoint === null || $expectedRevision !== 1) {
            throw IntegrationSecurityException::conflict();
        }
        return $this->endpoint = new WebhookEndpoint($endpointKey, $this->endpoint->name, $this->endpoint->url, $this->endpoint->events, 'active', 2, $this->endpoint->createdAt);
    }
    public function disableEndpoint(TenantContext $context, string $endpointKey, int $expectedRevision): WebhookEndpoint
    {
        if ($this->endpoint === null) {
            throw IntegrationSecurityException::endpointNotFound();
        }
        return $this->endpoint = new WebhookEndpoint($endpointKey, $this->endpoint->name, $this->endpoint->url, $this->endpoint->events, 'disabled', $expectedRevision + 1, $this->endpoint->createdAt);
    }
    public function activeEndpointKeysForEvent(int $tenantId, string $eventType): array
    {
        return $this->endpoint !== null && $tenantId === 101 && in_array($eventType, $this->endpoint->events, true) ? [['endpoint_key' => $this->endpoint->endpointKey]] : [];
    }
    public function enqueueDelivery(int $tenantId, string $endpointKey, TrustedWebhookEvent $event, DateTimeImmutable $now): string
    {
        ++$this->deliveryCount;
        return 'delivery_' . str_repeat('d', 32);
    }
    public function claimDelivery(int $tenantId, string $leaseDigest, int $leaseSeconds, DateTimeImmutable $now): ?WebhookDelivery
    {
        if ($this->claimed) {
            return null;
        } $this->claimed = true;
        return $this->delivery;
    }
    public function completeDelivery(WebhookDelivery $delivery, int $statusCode, int $durationMs, DateTimeImmutable $now): void
    {
        $this->completed = true;
    }
    public function failDelivery(WebhookDelivery $delivery, string $safeCode, bool $retryable, ?int $statusCode, int $durationMs, DateTimeImmutable $now): void
    {
        $this->failure = [$safeCode, $retryable, $statusCode];
    }
    public function purgeExpiredDeliveryData(DateTimeImmutable $payloadCutoff, DateTimeImmutable $evidenceCutoff): array
    {
        return [];
    }
    public function deliveryRecords(int $tenantId, int $page, int $pageSize): IntegrationSecurityPage
    {
        return new IntegrationSecurityPage([], $page, $pageSize, 0);
    }
    public function deliveryAttemptRecords(int $tenantId, string $deliveryKey, int $page, int $pageSize): IntegrationSecurityPage
    {
        return new IntegrationSecurityPage([], $page, $pageSize, 0);
    }
    public function sessionDevices(int $tenantId, int $accountId, string $currentSessionKey): array
    {
        return [new SessionDevice($currentSessionKey, 'admin-web', 'active', true, '203.0.113.*', str_repeat('a', 12), '2026-07-24T10:00:00.000Z', '2026-07-24T10:00:00.000Z', '2026-07-25T10:00:00.000Z', null)];
    }
    public function revokeOwnSession(TenantContext $context, string $sessionKey): SessionDevice
    {
        return new SessionDevice($sessionKey, 'admin-web', 'revoked', hash_equals($context->sessionKey, $sessionKey), null, null, '2026-07-24T10:00:00.000Z', '2026-07-24T10:00:00.000Z', '2026-07-25T10:00:00.000Z', '2026-07-24T10:01:00.000Z');
    }
}

$publicResolver = new class implements HostAddressResolver {
    public function resolve(string $host): array
    {
        return $host === 'hooks.example.com' ? ['93.184.216.34'] : ($host === 'mixed.example.com' ? ['93.184.216.34', '127.0.0.1'] : []);
    }
};
$policy = new WebhookDestinationPolicy($publicResolver);
$destination = $policy->approve('https://hooks.example.com/events?version=1');
same(['93.184.216.34'], $destination->approvedAddresses, 'public DNS pinned');
expectCode('WEBHOOK_DESTINATION_DENIED', fn() => $policy->approve('http://hooks.example.com/'), 'HTTPS required');
expectCode('WEBHOOK_DESTINATION_DENIED', fn() => $policy->approve('https://localhost/'), 'localhost denied');
expectCode('WEBHOOK_DESTINATION_DENIED', fn() => $policy->approve('https://169.254.169.254/latest/meta-data'), 'metadata address denied');
expectCode('WEBHOOK_DESTINATION_DENIED', fn() => $policy->approve('https://mixed.example.com/'), 'mixed DNS denied');
foreach ([
    '0.1.2.3', '10.1.2.3', '100.64.0.1', '127.0.0.1', '169.254.169.254', '172.31.0.1',
    '192.0.0.1', '192.0.2.1', '192.88.99.1', '192.168.1.1', '198.18.0.1', '198.51.100.1',
    '203.0.113.1', '224.0.0.1', '240.0.0.1', '::1', '::ffff:192.0.2.1', '64:ff9b::0808:0808',
    '64:ff9b:1::1', '100::1', '2001::1', '2001:db8::1', '2002::1', '3fff::1', '5f00::1',
    'fc00::1', 'fe80::1', 'ff00::1',
] as $deniedAddress) {
    expectCode('WEBHOOK_DESTINATION_DENIED', fn() => $policy->approve('https://' . (str_contains($deniedAddress, ':') ? '[' . $deniedAddress . ']' : $deniedAddress) . '/'), 'CIDR denied ' . $deniedAddress);
}

$protector = new AesGcmWebhookSecretProtector('test-key', base64_encode(str_repeat('k', 32)));
$binding = '101:webhook_' . str_repeat('b', 32);
$sealed = $protector->seal('whsec_' . str_repeat('s', 43), $binding);
truth(!str_contains($sealed['ciphertext'], 'whsec_'), 'secret encrypted at rest');
same('whsec_' . str_repeat('s', 43), $protector->open($sealed['ciphertext'], 'test-key', $binding), 'secret opens');
expectCode('WEBHOOK_SECRET_INVALID', fn() => $protector->open($sealed['ciphertext'], 'wrong-key', $binding), 'key mismatch fails');
expectCode('WEBHOOK_SECRET_INVALID', fn() => $protector->open($sealed['ciphertext'], 'test-key', '102:webhook_' . str_repeat('b', 32)), 'row binding fails');

$repository = new MemoryRepository();
$scopeCatalog = new MachineScopeCatalog(['data.export.read', 'data.export.write', 'webhook.publish']);
$scopeResolver = new class implements MachineScopeGrantResolver {
    public function grantableScopes(AuthorizedOperationContext $context): array
    {
        return $context->tenantContext->tenantId === 101 && $context->tenantContext->memberId === 501
            ? ['data.export.read', 'data.export.write'] : [];
    }
};
$machines = new MachineIdentityService($repository, new MachineScopeGrantPolicy($scopeCatalog, $scopeResolver));
$provisioned = $machines->create(context('machine-manage'), 'Export worker', ['data.export.write', 'data.export.read'], new DateTimeImmutable('2030-01-01T00:00:00Z'));
truth(str_starts_with($provisioned->token, 'pa_mi_'), 'token disclosed once');
truth(!str_contains(json_encode($provisioned->identity, JSON_THROW_ON_ERROR), $provisioned->token), 'ordinary identity redacts token');
same(['data.export.read', 'data.export.write'], $provisioned->identity->scopes, 'scopes normalized');
$principal = $machines->authenticate($provisioned->token, ['data.export.read'], new DateTimeImmutable('2026-07-24T10:00:00Z'));
same('machine', $principal->audience, 'machine audience');
same(101, $principal->tenantId, 'machine tenant');
$repository->auth['scopes'] = ['legacy.scope'];
expectCode('MACHINE_SCOPE_DENIED', fn() => $machines->authenticate($provisioned->token, [], new DateTimeImmutable('2026-07-24T10:00:00Z')), 'persisted stale scope rejected');
same(1, $repository->machineTouches, 'stale scope rejected before touch');
$repository->auth['scopes'] = ['data.export.read', 'data.export.write'];
expectCode('MACHINE_SCOPE_DENIED', fn() => $machines->authenticate($provisioned->token, ['admin.full'], new DateTimeImmutable('2026-07-24T10:00:00Z')), 'scope denied');
expectCode('INTEGRATION_INPUT_INVALID', fn() => $machines->create(context('machine-manage'), 'Unknown scope', ['admin.full'], null), 'unknown grant scope rejected');
expectCode('MACHINE_SCOPE_DENIED', fn() => $machines->create(context('machine-manage', 102, 302, 502), 'Untrusted issuer', ['data.export.read'], null), 'issuer grants derived from context');
expectCode('INTEGRATION_PERMISSION_DENIED', fn() => $machines->create(context('machine-read'), 'Denied', ['data.export.read'], null), 'operation permission fail closed');
expectCode('MACHINE_SCOPE_DENIED', fn() => $machines->rotate(context('machine-manage', 101, 302, 502, '01J00000000000000000000001'), $provisioned->identity->identityKey, 1), 'rotation rechecks issuer grants');
$rotated = $machines->rotate(context('machine-manage'), $provisioned->identity->identityKey, 1);
truth(!hash_equals($provisioned->token, $rotated->token), 'rotation changes token');
$machines->revoke(context('machine-manage'), $rotated->identity->identityKey, 1);
expectCode('MACHINE_TOKEN_INVALID', fn() => $machines->authenticate($rotated->token, [], new DateTimeImmutable('2026-07-24T10:00:00Z')), 'revoked token denied');

$webhooks = new WebhookService($repository, $policy, $protector);
$webhook = $webhooks->create(context('webhook-manage'), 'Audit receiver', 'https://hooks.example.com/events', ['audit.event.created']);
truth(str_starts_with($webhook->signingSecret, 'whsec_'), 'signing secret initial disclosure');
truth(!str_contains(json_encode($webhook->endpoint, JSON_THROW_ON_ERROR), 'whsec_'), 'endpoint never echoes secret');
$event = new TrustedWebhookEvent('event:2026:00000001', 'audit.event.created', ['z' => 2, 'a' => 1]);
same('{"a":1,"z":2}', $event->canonicalPayload(), 'payload canonical');
$publisher = new TrustedWebhookPublisher($repository);
same($publisher->publish(101, $event), $publisher->publish(101, $event), 'stable delivery key');

$sealedDispatch = $protector->seal($webhook->signingSecret, '101:' . $webhook->endpoint->endpointKey);
$repository->delivery = new WebhookDelivery(1, 101, $webhook->endpoint->endpointKey, 'delivery_' . str_repeat('d', 32), 'audit.event.created', $event->canonicalPayload(), hash('sha256', $event->canonicalPayload()), 'https://hooks.example.com/events', $sealedDispatch['ciphertext'], $sealedDispatch['key_id'], 1, str_repeat('a', 64));
$captured = null;
$transport = new class ($captured) implements WebhookTransport {
    public ?WebhookRequest $request = null;
    public function __construct(& $captured)
    {
        $captured = &$this->request;
    }
    public function send(WebhookRequest $request): WebhookResponse
    {
        $this->request = $request;
        return new WebhookResponse(204, 12);
    }
};
$dispatcher = new WebhookDispatcher($repository, $policy, $protector, $transport);
truth($dispatcher->runOne(101, new DateTimeImmutable('2026-07-24T10:00:00Z')), 'delivery claimed');
truth($repository->completed, 'delivery completed');
truth($transport->request !== null, 'request captured');
same(false, $transport->request?->followRedirects, 'redirect disabled');
truth(preg_match('/^v1=[0-9a-f]{64}$/D', $transport->request?->headers['X-Peanut-Signature'] ?? '') === 1, 'signature shape');

$sessions = new SessionSecurityService($repository);
same(1, count($sessions->list(context('session-read'))), 'self sessions listed');
same('revoked', $sessions->revoke(context('session-revoke'), '01J00000000000000000000000')->status, 'self session revoked');
expectCode('INTEGRATION_PERMISSION_DENIED', fn() => $sessions->list(context('machine-read')), 'session permission denied');

echo "integration-security feature harness: PASS\n";
