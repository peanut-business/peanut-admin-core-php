<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Webhook;

use DateTimeImmutable;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use PeanutAdmin\IntegrationSecurity\Crypto\WebhookSecretProtector;
use PeanutAdmin\IntegrationSecurity\Persistence\IntegrationSecurityRepository;
use Throwable;

final readonly class WebhookDispatcher
{
    public function __construct(
        private IntegrationSecurityRepository $repository,
        private WebhookDestinationPolicy $destinations,
        private WebhookSecretProtector $secrets,
        private WebhookTransport $transport,
    ) {}

    public function runOne(int $tenantId, ?DateTimeImmutable $now = null): bool
    {
        if ($tenantId < 1) {
            throw IntegrationSecurityException::invalid();
        }
        $now ??= new DateTimeImmutable('now');
        $lease = bin2hex(random_bytes(32));
        $delivery = $this->repository->claimDelivery($tenantId, hash('sha256', $lease), 30, $now);
        if ($delivery === null) {
            return false;
        }

        try {
            $destination = $this->destinations->approve($delivery->url);
            $secret = $this->secrets->open($delivery->secretCiphertext, $delivery->secretKeyId, $delivery->tenantId . ':' . $delivery->endpointKey);
            $timestamp = (string) $now->getTimestamp();
            $canonical = 'v1.' . $timestamp . '.' . $delivery->deliveryKey . '.' . $delivery->payloadSha256;
            $response = $this->transport->send(new WebhookRequest($destination, $delivery->payloadJson, [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Peanut-Admin-Webhook/0.1',
                'X-Peanut-Delivery' => $delivery->deliveryKey,
                'X-Peanut-Event' => $delivery->eventType,
                'X-Peanut-Timestamp' => $timestamp,
                'X-Peanut-Signature' => 'v1=' . hash_hmac('sha256', $canonical, $secret),
            ]));
        } catch (IntegrationSecurityException $exception) {
            $this->repository->failDelivery($delivery, $exception->problemCode, false, null, 0, $now);
            return true;
        } catch (Throwable) {
            $this->repository->failDelivery($delivery, 'WEBHOOK_TRANSPORT_FAILED', true, null, 0, $now);
            return true;
        }

        if ($response->statusCode >= 200 && $response->statusCode < 300) {
            $this->repository->completeDelivery($delivery, $response->statusCode, $response->durationMs, $now);
            return true;
        }
        if ($response->statusCode >= 300 && $response->statusCode < 400) {
            $this->repository->failDelivery($delivery, 'WEBHOOK_REDIRECT_DENIED', false, $response->statusCode, $response->durationMs, $now);
            return true;
        }
        $retryable = in_array($response->statusCode, [408, 425, 429], true) || $response->statusCode >= 500;
        $this->repository->failDelivery(
            $delivery,
            $retryable ? 'WEBHOOK_REMOTE_RETRYABLE' : 'WEBHOOK_REMOTE_REJECTED',
            $retryable,
            $response->statusCode,
            $response->durationMs,
            $now,
        );
        return true;
    }
}
