<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

use PeanutAdmin\IntegrationSecurity\Crypto\WebhookSecretProtector;
use PeanutAdmin\IntegrationSecurity\Package;
use PeanutAdmin\IntegrationSecurity\Persistence\IntegrationSecurityRepository;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookDestinationPolicy;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

final readonly class WebhookService
{
    public function __construct(
        private IntegrationSecurityRepository $repository,
        private WebhookDestinationPolicy $destinations,
        private WebhookSecretProtector $secrets,
    ) {}

    /** @param list<string> $events */
    public function create(AuthorizedOperationContext $context, string $name, string $url, array $events): ProvisionedWebhookEndpoint
    {
        $this->assertOperation($context, 'webhook-manage');
        [$name, $events] = $this->validate($name, $events);
        $destination = $this->destinations->approve($url);
        $endpointKey = 'webhook_' . bin2hex(random_bytes(16));
        $secret = self::secret();
        $sealed = $this->secrets->seal($secret, $context->tenantContext->tenantId . ':' . $endpointKey);
        $endpoint = $this->repository->createEndpoint(
            $context->tenantContext,
            $endpointKey,
            $name,
            $destination->url,
            $events,
            $sealed['ciphertext'],
            $sealed['key_id'],
        );
        return new ProvisionedWebhookEndpoint($endpoint, $secret);
    }

    /** @return list<WebhookEndpoint> */
    public function list(AuthorizedOperationContext $context): array
    {
        $this->assertOperation($context, 'webhook-read');
        return $this->repository->endpoints($context->tenantContext->tenantId);
    }

    public function rotateSecret(AuthorizedOperationContext $context, string $endpointKey, int $expectedRevision): ProvisionedWebhookEndpoint
    {
        $this->assertOperation($context, 'webhook-manage');
        $this->assertEndpointKey($endpointKey);
        if ($expectedRevision < 1) {
            throw IntegrationSecurityException::invalid();
        }
        $secret = self::secret();
        $sealed = $this->secrets->seal($secret, $context->tenantContext->tenantId . ':' . $endpointKey);
        $endpoint = $this->repository->rotateEndpointSecret($context->tenantContext, $endpointKey, $expectedRevision, $sealed['ciphertext'], $sealed['key_id']);
        return new ProvisionedWebhookEndpoint($endpoint, $secret);
    }

    public function disable(AuthorizedOperationContext $context, string $endpointKey, int $expectedRevision): WebhookEndpoint
    {
        $this->assertOperation($context, 'webhook-manage');
        $this->assertEndpointKey($endpointKey);
        if ($expectedRevision < 1) {
            throw IntegrationSecurityException::invalid();
        }
        return $this->repository->disableEndpoint($context->tenantContext, $endpointKey, $expectedRevision);
    }

    /** @param list<string> $events @return array{string,list<string>} */
    private function validate(string $name, array $events): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw IntegrationSecurityException::invalid();
        }
        $unique = [];
        foreach ($events as $event) {
            if (!is_string($event) || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $event) !== 1 || strlen($event) > 96) {
                throw IntegrationSecurityException::invalid();
            }
            $unique[$event] = true;
        }
        if ($unique === [] || count($unique) > 32) {
            throw IntegrationSecurityException::invalid();
        }
        $events = array_keys($unique);
        sort($events, SORT_STRING);
        return [$name, $events];
    }

    private function assertOperation(AuthorizedOperationContext $context, string $operation): void
    {
        if (!hash_equals(Package::RESOURCE_KEY, $context->resourceKey) || !hash_equals($operation, $context->operation)) {
            throw IntegrationSecurityException::denied();
        }
    }

    private function assertEndpointKey(string $key): void
    {
        if (preg_match('/^webhook_[0-9a-f]{32}$/D', $key) !== 1) {
            throw IntegrationSecurityException::endpointNotFound();
        }
    }

    private static function secret(): string
    {
        return 'whsec_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
