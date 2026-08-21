<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Platform;

use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Audit\GovernanceAuditFilter;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Kernel\Authorization\Persistence\PdoAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Platform\Application\PlatformWorkspaceQueryService;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class PlatformWorkspaceQueryServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-17 04:00:00.000';

    private PlatformWorkspaceQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        (new CorePermissionCatalogSynchronizer(
            new PdoAuthorizationCatalogRepository($this->database),
        ))->synchronize();
        $this->service = new PlatformWorkspaceQueryService($this->database);
    }

    public function testListsPlatformResourcesWithPaginationAndStableApiShapes(): void
    {
        $alphaId = $this->tenant('alpha', 'Alpha Tenant');
        $this->tenant('beta', 'Beta Tenant');
        $accountId = $this->account('Platform Operator', 'operator@example.test');
        $operatorId = $this->insert('pa_platform_operator', [
            'account_id' => $accountId,
            'display_name' => 'Primary Operator',
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $roleId = $this->insert('pa_platform_role', [
            'key' => 'platform.auditor',
            'name' => 'Platform Auditor',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_platform_operator_role', [
            'platform_operator_id' => $operatorId,
            'platform_role_id' => $roleId,
            'assigned_at' => self::NOW,
        ]);
        $permissionId = (int) $this->query(
            "SELECT id FROM pa_permission WHERE `key` = 'platform.audit.read'",
        )->fetchColumn();
        $this->insert('pa_platform_role_permission', [
            'platform_role_id' => $roleId,
            'permission_id' => $permissionId,
            'granted_at' => self::NOW,
        ]);
        $eventId = $this->insert('pa_platform_audit_event', [
            'event_type' => 'tenant.created',
            'action' => 'platform.tenant.create',
            'outcome' => 'success',
            'operator_id' => $operatorId,
            'account_id' => $accountId,
            'target_type' => 'tenant',
            'target_id' => (string) $alphaId,
            'request_id' => 'req_platform_workspace',
            'metadata_json' => '{"role_id":"7","permission_count":3,"password":"hidden"}',
            'occurred_at' => self::NOW,
        ]);

        $tenants = $this->service->tenants(new PageRequest(1, 1));
        self::assertSame(2, $tenants['total']);
        self::assertCount(1, $tenants['items']);
        self::assertIsString($tenants['items'][0]['id']);
        self::assertSame((string) $alphaId, $this->service->tenant($alphaId)['id']);

        $operators = $this->service->operators(new PageRequest());
        self::assertSame(1, $operators['total']);
        self::assertSame('operator@example.test', $operators['items'][0]['email']);
        self::assertSame(['platform.auditor'], $operators['items'][0]['role_keys']);
        self::assertSame((string) $operatorId, $this->service->operator($operatorId)['id']);

        $roles = $this->service->roles(new PageRequest());
        self::assertSame(1, $roles['total']);
        self::assertSame(1, $roles['items'][0]['permission_count']);
        self::assertSame(['platform.audit.read'], $this->service->role($roleId)['permission_keys']);

        $permissions = $this->service->permissions();
        self::assertNotEmpty($permissions);
        self::assertSame([], array_values(array_filter(
            array_column($permissions, 'key'),
            static fn(string $key): bool => !str_starts_with($key, 'platform.'),
        )));

        $audit = $this->service->auditEvents(new PageRequest(), new GovernanceAuditFilter(
            'tenant.created',
            'platform.tenant.create',
            AuditOutcome::Success,
            'req_platform_workspace',
            'tenant',
            (string) $alphaId,
        ));
        self::assertSame(1, $audit['total']);
        self::assertSame('Primary Operator', $audit['items'][0]['operator_label']);
        self::assertSame((string) $alphaId, $audit['items'][0]['target_tenant_id']);
        self::assertSame(
            ['permission_count' => 3, 'role_id' => '7'],
            $this->service->auditEvent((string) $eventId)['metadata'],
        );
    }

    public function testDetailQueriesDoNotExposeMissingResources(): void
    {
        foreach ([
            fn(): array => $this->service->tenant(999999),
            fn(): array => $this->service->operator(999999),
            fn(): array => $this->service->role(999999),
        ] as $operation) {
            try {
                $operation();
            } catch (AdminAccessException $exception) {
                self::assertSame(404, $exception->httpStatus);

                continue;
            }

            self::fail('A missing platform resource must return the uniform not-found error.');
        }
    }

    private function tenant(string $code, string $name): int
    {
        return $this->insert('pa_tenant', [
            'code' => $code,
            'name' => $name,
            'display_name' => $name,
            'status' => 'active',
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function account(string $name, string $email): int
    {
        $accountId = $this->insert('pa_account', [
            'display_name' => $name,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_credential', [
            'account_id' => $accountId,
            'kind' => 'email_password',
            'identifier_type' => 'email',
            'identifier_normalized' => $email,
            'secret_hash' => password_hash('platform correct horse password', PASSWORD_ARGON2ID),
            'verified_at' => self::NOW,
            'secret_changed_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return $accountId;
    }
}
