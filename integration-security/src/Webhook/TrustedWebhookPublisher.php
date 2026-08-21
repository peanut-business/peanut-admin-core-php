<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Webhook;

use DateTimeImmutable;
use PeanutAdmin\IntegrationSecurity\Persistence\IntegrationSecurityRepository;

final readonly class TrustedWebhookPublisher
{
    public function __construct(private IntegrationSecurityRepository $repository) {}

    /** @return list<string> delivery keys */
    public function publish(int $tenantId, TrustedWebhookEvent $event, ?DateTimeImmutable $now = null): array
    {
        if ($tenantId < 1) {
            throw \PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException::invalid();
        }
        $now ??= new DateTimeImmutable('now');
        return $this->repository->transaction(function () use ($tenantId, $event, $now): array {
            $keys = [];
            foreach ($this->repository->activeEndpointKeysForEvent($tenantId, $event->eventType) as $endpoint) {
                $keys[] = $this->repository->enqueueDelivery($tenantId, $endpoint['endpoint_key'], $event, $now);
            }
            return $keys;
        });
    }
}
