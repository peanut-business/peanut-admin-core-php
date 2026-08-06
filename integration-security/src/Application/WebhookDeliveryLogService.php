<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

use PeanutAdmin\IntegrationSecurity\Package;
use PeanutAdmin\IntegrationSecurity\Persistence\IntegrationSecurityRepository;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

final readonly class WebhookDeliveryLogService
{
    public function __construct(private IntegrationSecurityRepository $repository) {}

    public function deliveries(AuthorizedOperationContext $context, int $page, int $pageSize): IntegrationSecurityPage
    {
        $this->assertRead($context);
        $this->assertPage($page, $pageSize);
        return $this->repository->deliveryRecords($context->tenantContext->tenantId, $page, $pageSize);
    }

    public function attempts(AuthorizedOperationContext $context, string $deliveryKey, int $page, int $pageSize): IntegrationSecurityPage
    {
        $this->assertRead($context);
        if (preg_match('/^delivery_[0-9a-f]{32}$/D', $deliveryKey) !== 1) {
            throw IntegrationSecurityException::invalid();
        }
        $this->assertPage($page, $pageSize);
        return $this->repository->deliveryAttemptRecords($context->tenantContext->tenantId, $deliveryKey, $page, $pageSize);
    }

    private function assertRead(AuthorizedOperationContext $context): void
    {
        if (!hash_equals(Package::RESOURCE_KEY, $context->resourceKey) || !hash_equals('delivery-read', $context->operation)) {
            throw IntegrationSecurityException::denied();
        }
    }

    private function assertPage(int $page, int $pageSize): void
    {
        if ($page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw IntegrationSecurityException::invalid();
        }
    }
}
