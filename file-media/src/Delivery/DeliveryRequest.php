<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Delivery;

use DateTimeImmutable;
use PeanutAdmin\FileMedia\Application\FileMediaException;
use PeanutAdmin\FileMedia\Application\FileObject;
use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class DeliveryRequest
{
    public function __construct(
        public TenantContext $context,
        public FileObject $file,
        public DeliveryVisibility $visibility,
        public ReplayMode $replayMode,
        public DateTimeImmutable $issuedAt,
        public int $ttlSeconds,
        public bool $permissionGranted,
    ) {
        if ($context->tenantId !== $file->tenantId || $file->status !== 'ready') {
            throw FileMediaException::notFound();
        }
        if (!$permissionGranted) {
            throw FileMediaException::deliveryDenied();
        }
        if ($ttlSeconds < 1) {
            throw FileMediaException::deliveryInvalid();
        }
    }
}
