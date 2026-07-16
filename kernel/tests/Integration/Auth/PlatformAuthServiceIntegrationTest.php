<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\Persistence\PdoPlatformAuthRepository;
use PeanutAdmin\Kernel\Auth\Persistence\PdoTenantAuthRepository;
use PeanutAdmin\Kernel\Auth\PlatformAuthService;
use PeanutAdmin\Kernel\Auth\PlatformRefreshCookie;
use PeanutAdmin\Kernel\Auth\TenantAuthService;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoAuditRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoIdentityRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoMembershipRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoPlatformRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoTenantRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoTransactionManager;
use PeanutAdmin\Kernel\Platform\Bootstrap\BootstrapService;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';
require_once __DIR__ . '/MutableClock.php';

final class PlatformAuthServiceIntegrationTest extends DatabaseTestCase
{
    private MutableClock $clock;
    private PlatformAuthService $platformAuth;
    private TenantAuthService $tenantAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();

        $transactions = new PdoTransactionManager($this->database);
        $passwords = new PasswordHasher();
        $bootstrap = new BootstrapService(
            $transactions,
            new PdoIdentityRepository($this->database),
            new PdoTenantRepository($this->database),
            new PdoMembershipRepository($this->database),
            new PdoPlatformRepository($this->database),
            new PdoAuditRepository($this->database),
            $passwords,
        );
        $bootstrap->bootstrapPlatformOwner(
            'platform@example.com',
            'platform correct horse password',
            'Platform Owner',
            'fixture-platform-auth',
        );

        $this->clock = new MutableClock(new DateTimeImmutable(
            '2026-07-16 03:00:00.000',
            new DateTimeZone('UTC'),
        ));
        $tokens = new TokenIssuer();
        $this->platformAuth = new PlatformAuthService(
            $transactions,
            new PdoPlatformAuthRepository($this->database),
            $passwords,
            $this->clock,
            $tokens,
        );
        $this->tenantAuth = new TenantAuthService(
            $transactions,
            new PdoTenantAuthRepository($this->database),
            $passwords,
            $this->clock,
            $tokens,
            'test-platform-audience-hmac-key-32-bytes',
        );
    }

    public function testPlatformLoginUsesSeparateSessionTablesContextAndCookie(): void
    {
        $authentication = $this->login();
        self::assertStringStartsWith('pa_pat_', $authentication->tokens->access->expose());
        self::assertStringStartsWith('pa_prt_', $authentication->tokens->refresh->expose());
        self::assertSame('platform', $authentication->responseData()['context']['audience']);
        self::assertArrayNotHasKey('tenantId', get_object_vars($authentication->context));
        self::assertSame(1, $this->countRows('pa_platform_session'));
        self::assertSame(0, $this->countRows('pa_tenant_session'));
        self::assertSame(0, $this->countRows('pa_tenant_member'));

        $cookie = PlatformRefreshCookie::issue($authentication->tokens->refresh);
        self::assertStringStartsWith('__Host-pa_platform_refresh=', $cookie);
        self::assertStringNotContainsString('__Host-pa_tenant_refresh=', $cookie);
    }

    public function testAudienceCannotCrossTenantAndPlatformGuards(): void
    {
        $authentication = $this->login();
        self::assertSame(
            'AUTH_AUDIENCE_MISMATCH',
            $this->authError(fn() => $this->tenantAuth->context(
                $authentication->tokens->access->expose(),
                'request-platform-to-tenant',
            ))->errorCode,
        );
        self::assertSame(
            'AUTH_AUDIENCE_MISMATCH',
            $this->authError(fn() => $this->platformAuth->context(
                'pa_tat_tenant-token',
                'request-tenant-to-platform',
            ))->errorCode,
        );
    }

    public function testPlatformRefreshRotatesAndOperatorRevisionInvalidates(): void
    {
        $authentication = $this->login();
        $rotated = $this->platformAuth->refresh(
            $authentication->tokens->refresh->expose(),
            '127.0.0.1',
            null,
            'request-platform-refresh',
        );
        self::assertNotSame(
            $authentication->tokens->access->expose(),
            $rotated->tokens->access->expose(),
        );

        $this->database->exec(<<<'SQL'
UPDATE pa_platform_operator
SET status = 'suspended', security_revision = security_revision + 1
WHERE id = 1
SQL);
        self::assertSame(
            'AUTH_ACCOUNT_UNAVAILABLE',
            $this->authError(fn() => $this->platformAuth->context(
                $rotated->tokens->access->expose(),
                'request-operator-suspended',
            ))->errorCode,
        );
    }

    public function testExpiredAndRotatedAccessTokensDoNotRevokeTheRefreshFamily(): void
    {
        $authentication = $this->login();
        $oldAccess = $authentication->tokens->access->expose();
        $this->clock->advance('+15 minutes');
        self::assertSame(
            'AUTH_SESSION_EXPIRED',
            $this->authError(fn() => $this->platformAuth->context(
                $oldAccess,
                'request-platform-expired-access',
            ))->errorCode,
        );

        $rotated = $this->platformAuth->refresh(
            $authentication->tokens->refresh->expose(),
            '127.0.0.1',
            'Test Agent',
            'request-platform-refresh-after-expiry',
        );
        self::assertSame(1, $this->platformAuth->context(
            $rotated->tokens->access->expose(),
            'request-platform-new-access',
        )->operatorId);
        self::assertSame(
            'AUTH_TOKEN_INVALID',
            $this->authError(fn() => $this->platformAuth->context(
                $oldAccess,
                'request-platform-late-old-access',
            ))->errorCode,
        );
        self::assertSame(1, $this->platformAuth->context(
            $rotated->tokens->access->expose(),
            'request-platform-new-access-still-valid',
        )->operatorId);

        $lastSeen = $this->query('SELECT last_seen_at FROM pa_platform_session WHERE id = 1')->fetchColumn();
        self::assertSame('2026-07-16 03:15:00.000', $lastSeen);
    }

    private function login(): \PeanutAdmin\Kernel\Auth\PlatformAuthentication
    {
        return $this->platformAuth->login(
            'platform@example.com',
            'platform correct horse password',
            '127.0.0.1',
            'Test Agent',
            'request-platform-login',
        );
    }

    private function authError(callable $operation): AuthException
    {
        try {
            $operation();
        } catch (AuthException $exception) {
            self::addToAssertionCount(1);

            return $exception;
        }

        self::fail('Expected platform authentication to fail.');
    }

    private function countRows(string $table): int
    {
        return (int) $this->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }
}
