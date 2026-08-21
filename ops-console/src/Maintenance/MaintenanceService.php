<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Maintenance;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\OpsConsole\Application\OpsConsoleException;
use PeanutAdmin\OpsConsole\Application\PlatformPermissionChecker;
use PeanutAdmin\OpsConsole\Package;
use PeanutAdmin\OpsConsole\Support\Contract;
use PeanutAdmin\OpsConsole\Task\OpsAuditEvent;
use Throwable;

final readonly class MaintenanceService
{
    public function __construct(
        private PlatformPermissionChecker $permissions,
        private MaintenanceReasonRegistry $reasons,
        private MaintenanceWindowStore $store,
    ) {}

    public function current(PlatformContext $context): ?MaintenanceWindow
    {
        $this->assertAllowed($context, Package::READ_PERMISSION);
        try {
            $window = $this->store->current($context);
            return $window === null ? null : $this->validatedWindow($window);
        } catch (OpsConsoleException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw OpsConsoleException::internal();
        }
    }

    public function schedule(
        PlatformContext $context,
        string $reasonKey,
        string $startsAt,
        string $endsAt,
        int $expectedRevision,
        string $idempotencyKey,
    ): MaintenanceWindow {
        $this->assertAllowed($context, Package::MAINTENANCE_PERMISSION);
        if ($expectedRevision < 0) {
            throw OpsConsoleException::revisionConflict();
        }
        $reason = $this->reasons->require($reasonKey);
        try {
            Contract::instant($startsAt);
            Contract::instant($endsAt);
            $start = new DateTimeImmutable($startsAt);
            $end = new DateTimeImmutable($endsAt);
        } catch (Throwable) {
            throw OpsConsoleException::maintenanceInvalid();
        }
        $startMilliseconds = $start->getTimestamp() * 1000 + (int) $start->format('v');
        $endMilliseconds = $end->getTimestamp() * 1000 + (int) $end->format('v');
        $duration = $endMilliseconds - $startMilliseconds;
        if ($duration < 1 || $duration > 86400000) {
            throw OpsConsoleException::maintenanceInvalid();
        }
        [$idempotencyDigest, $requestDigest] = $this->digests($idempotencyKey, [
            'reason_key' => $reason, 'starts_at' => $startsAt, 'ends_at' => $endsAt,
            'expected_revision' => $expectedRevision,
        ]);
        $candidate = new MaintenanceWindow(
            'maintenance_' . bin2hex(random_bytes(16)),
            'scheduled',
            $reason,
            $startsAt,
            $endsAt,
            max(1, $expectedRevision + 1),
        );
        $audit = new OpsAuditEvent('platform.ops.maintenance.scheduled', 'maintenance.schedule', [
            'maintenance_key' => $candidate->maintenanceKey,
            'revision' => $candidate->revision,
            'idempotency_digest' => $idempotencyDigest,
            'request_digest' => $requestDigest,
        ]);
        try {
            return $this->validatedWindow(
                $this->store->schedule(
                    $context,
                    $candidate,
                    $expectedRevision,
                    $idempotencyDigest,
                    $requestDigest,
                    $audit,
                ),
            );
        } catch (OpsConsoleException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw OpsConsoleException::internal();
        }
    }

    public function close(
        PlatformContext $context,
        string $maintenanceKey,
        int $expectedRevision,
        string $idempotencyKey,
    ): MaintenanceWindow {
        $this->assertAllowed($context, Package::MAINTENANCE_PERMISSION);
        if ($expectedRevision < 1) {
            throw OpsConsoleException::revisionConflict();
        }
        try {
            Contract::opaqueKey($maintenanceKey, 'maintenance_');
        } catch (Throwable) {
            throw OpsConsoleException::maintenanceInvalid();
        }
        [$idempotencyDigest, $requestDigest] = $this->digests($idempotencyKey, [
            'maintenance_key' => $maintenanceKey, 'expected_revision' => $expectedRevision,
        ]);
        $audit = new OpsAuditEvent('platform.ops.maintenance.closed', 'maintenance.close', [
            'maintenance_key' => $maintenanceKey, 'revision' => $expectedRevision,
            'idempotency_digest' => $idempotencyDigest, 'request_digest' => $requestDigest,
        ]);
        try {
            return $this->validatedWindow(
                $this->store->close(
                    $context,
                    $maintenanceKey,
                    $expectedRevision,
                    $idempotencyDigest,
                    $requestDigest,
                    $audit,
                ),
            );
        } catch (OpsConsoleException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw OpsConsoleException::internal();
        }
    }

    /** @param array<string, int|string> $request @return array{string, string} */
    private function digests(string $idempotencyKey, array $request): array
    {
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 200
            || preg_match('/^[\x21-\x7e]+$/D', $idempotencyKey) !== 1
        ) {
            throw OpsConsoleException::invalid();
        }
        return [
            hash('sha256', $idempotencyKey),
            hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private function assertAllowed(PlatformContext $context, string $permission): void
    {
        if (!$this->permissions->allows($context, $permission)) {
            throw OpsConsoleException::denied();
        }
    }

    private function validatedWindow(MaintenanceWindow $window): MaintenanceWindow
    {
        return new MaintenanceWindow(
            $window->maintenanceKey,
            $window->state,
            $window->reasonKey,
            $window->startsAt,
            $window->endsAt,
            $window->revision,
        );
    }
}
