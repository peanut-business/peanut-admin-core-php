<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\Persistence\PdoTenantAuthRepository;
use PeanutAdmin\Kernel\Auth\TenantAuthentication;
use PeanutAdmin\Kernel\Auth\TenantAuthService;
use PeanutAdmin\Kernel\Auth\TenantClientRegistry;
use PeanutAdmin\Kernel\Auth\TenantSelectionRequired;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Kernel\Http\TenantAuthEndpoint;
use PeanutAdmin\Kernel\Http\TenantRefreshCookie;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Identity\CredentialStatus;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Membership\TenantMemberStatus;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoAuditRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoIdentityRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoMembershipRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoPlatformRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoTenantRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoTransactionManager;
use PeanutAdmin\Kernel\Platform\Bootstrap\BootstrapService;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';
require_once __DIR__ . '/MutableClock.php';

final class TenantAuthServiceIntegrationTest extends DatabaseTestCase
{
    private const EMAIL = 'owner@example.com';
    private const PASSWORD = 'correct horse battery staple';

    private MutableClock $clock;
    private TenantAuthService $auth;
    private PdoIdentityRepository $identity;
    private PdoTenantRepository $tenants;
    private PdoMembershipRepository $memberships;
    private int $accountId;
    private int $alphaTenantId;
    private int $alphaMemberId;
    private int $betaTenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();

        $this->clock = new MutableClock(new DateTimeImmutable(
            '2026-07-16 02:00:00.000',
            new DateTimeZone('UTC'),
        ));
        $transactions = new PdoTransactionManager($this->database);
        $this->identity = new PdoIdentityRepository($this->database);
        $this->tenants = new PdoTenantRepository($this->database);
        $this->memberships = new PdoMembershipRepository($this->database);
        $platformRepository = new PdoPlatformRepository($this->database);
        $passwords = new PasswordHasher();
        $bootstrap = new BootstrapService(
            $transactions,
            $this->identity,
            $this->tenants,
            $this->memberships,
            $platformRepository,
            new PdoAuditRepository($this->database),
            $passwords,
        );
        $platform = $bootstrap->bootstrapPlatformOwner(
            self::EMAIL,
            self::PASSWORD,
            'Owner',
            'fixture-platform',
        );
        $alpha = $bootstrap->provisionTenantOwnerCandidate(
            $platform->operatorId,
            'alpha-company',
            'Alpha Company',
            self::EMAIL,
            null,
            'Alpha Owner',
            'fixture-alpha',
        );
        $bootstrap->activateTenantOwner(
            $platform->operatorId,
            $alpha->tenantId,
            $alpha->memberId,
            'fixture-alpha-owner-active',
        );
        $bootstrap->activateTenant(
            $platform->operatorId,
            $alpha->tenantId,
            'fixture-alpha-active',
        );
        $beta = $bootstrap->provisionTenantOwnerCandidate(
            $platform->operatorId,
            'beta-company',
            'Beta Company',
            self::EMAIL,
            null,
            'Beta Owner',
            'fixture-beta',
        );
        $bootstrap->activateTenantOwner(
            $platform->operatorId,
            $beta->tenantId,
            $beta->memberId,
            'fixture-beta-owner-active',
        );
        $bootstrap->activateTenant(
            $platform->operatorId,
            $beta->tenantId,
            'fixture-beta-active',
        );

        $this->accountId = $platform->accountId;
        $this->alphaTenantId = $alpha->tenantId;
        $this->alphaMemberId = $alpha->memberId;
        $this->betaTenantId = $beta->tenantId;
        $this->auth = new TenantAuthService(
            $transactions,
            new PdoTenantAuthRepository($this->database),
            $passwords,
            $this->clock,
            new TokenIssuer(),
            'test-identifier-hmac-secret-at-least-32-bytes',
        );
    }

    public function testLoginRequiresSelectionAndDoesNotLeakUnknownAccountState(): void
    {
        $selection = $this->login();
        self::assertInstanceOf(TenantSelectionRequired::class, $selection);
        self::assertCount(2, $selection->tenants);
        self::assertStringStartsWith('pa_lc_', $selection->challenge->expose());
        self::assertArrayNotHasKey('refresh_token', $selection->responseData());

        $unknown = $this->captureAuthError(fn() => $this->auth->login(
            'unknown@example.com',
            'incorrect horse battery staple',
            null,
            '127.0.0.1',
            'Test Agent',
            'request-unknown',
        ));
        $wrong = $this->captureAuthError(fn() => $this->auth->login(
            self::EMAIL,
            'incorrect horse battery staple',
            null,
            '127.0.0.1',
            'Test Agent',
            'request-wrong',
        ));
        self::assertSame('AUTH_INVALID_CREDENTIALS', $unknown->errorCode);
        self::assertSame($unknown->getMessage(), $wrong->getMessage());

        $eventPayload = $this->query(<<<'SQL'
SELECT CONCAT_WS('|', identifier_hmac, request_id, COALESCE(metadata_json, ''))
FROM pa_auth_security_event WHERE event_type = 'login_failed'
SQL)->fetchAll();
        $serialized = json_encode($eventPayload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::EMAIL, $serialized);
        self::assertStringNotContainsString('incorrect horse battery staple', $serialized);
    }

    public function testFiveFailuresLockCredentialTemporarily(): void
    {
        $existingSession = $this->selectAlpha($this->login());

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->captureAuthError(fn() => $this->auth->login(
                self::EMAIL,
                'wrong password value',
                null,
                '10.0.0.1',
                null,
                "request-failure-{$attempt}",
            ));
        }

        $row = $this->query(<<<'SQL'
SELECT status, failed_attempts, locked_until FROM pa_credential WHERE account_id = 1
SQL)->fetch();
        self::assertIsArray($row);
        self::assertSame('locked', $row['status']);
        self::assertSame(5, (int) $row['failed_attempts']);
        self::assertNotNull($row['locked_until']);
        self::assertSame(
            'AUTH_ACCOUNT_UNAVAILABLE',
            $this->captureAuthError(fn() => $this->auth->context(
                $existingSession->tokens->access->expose(),
                'request-before-lock-session',
            ))->errorCode,
        );

        $error = $this->captureAuthError(fn() => $this->auth->login(
            self::EMAIL,
            self::PASSWORD,
            null,
            '10.0.0.1',
            null,
            'request-during-lock',
        ));
        self::assertSame('AUTH_INVALID_CREDENTIALS', $error->errorCode);

        $this->clock->advance('+15 minutes');
        self::assertInstanceOf(TenantSelectionRequired::class, $this->login());
    }

    public function testIpWindowIsRateLimitedAcrossDifferentIdentifiers(): void
    {
        $repository = new PdoTenantAuthRepository($this->database);
        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $repository->recordSecurityEvent(
                'login_failed',
                'denied',
                'invalid_credentials',
                $this->accountId,
                null,
                null,
                hash('sha256', "different-identifier-{$attempt}"),
                "request-rate-fixture-{$attempt}",
                '10.10.0.1',
                null,
                $this->clock->now(),
            );
        }

        $error = $this->captureAuthError(fn() => $this->auth->login(
            self::EMAIL,
            self::PASSWORD,
            null,
            '10.10.0.1',
            null,
            'request-rate-limited',
        ));
        self::assertSame('AUTH_RATE_LIMITED', $error->errorCode);
        self::assertSame(429, $error->httpStatus);
    }

    public function testIdentifierWindowIsRateLimitedAcrossDifferentIpAddresses(): void
    {
        $repository = new PdoTenantAuthRepository($this->database);
        $identifierHmac = hash_hmac(
            'sha256',
            self::EMAIL,
            'test-identifier-hmac-secret-at-least-32-bytes',
        );
        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $repository->recordSecurityEvent(
                'login_failed',
                'denied',
                'invalid_credentials',
                $this->accountId,
                null,
                null,
                $identifierHmac,
                "request-identifier-rate-fixture-{$attempt}",
                "10.20.0.{$attempt}",
                null,
                $this->clock->now(),
            );
        }

        $error = $this->captureAuthError(fn() => $this->auth->login(
            self::EMAIL,
            self::PASSWORD,
            null,
            '10.30.0.1',
            null,
            'request-identifier-rate-limited',
        ));
        self::assertSame('AUTH_RATE_LIMITED', $error->errorCode);
        self::assertSame(429, $error->httpStatus);
    }

    public function testSuccessfulLoginUpgradesAnOutdatedPasswordHash(): void
    {
        $legacyHash = password_hash(self::PASSWORD, PASSWORD_BCRYPT);
        $statement = $this->database->prepare(
            'UPDATE pa_credential SET secret_hash = :secret_hash WHERE account_id = :account_id',
        );
        self::assertNotFalse($statement);
        $statement->execute(['secret_hash' => $legacyHash, 'account_id' => $this->accountId]);

        self::assertInstanceOf(TenantSelectionRequired::class, $this->login());

        $storedHash = $this->query(
            'SELECT secret_hash FROM pa_credential WHERE account_id = ' . $this->accountId,
        )->fetchColumn();
        self::assertIsString($storedHash);
        self::assertSame('argon2id', password_get_info($storedHash)['algoName']);
        self::assertTrue(password_verify(self::PASSWORD, $storedHash));
    }

    public function testSelectionCreatesHashedTokensAndFixedRefreshCookie(): void
    {
        $authentication = $this->selectAlpha($this->login());
        self::assertSame($this->alphaTenantId, $authentication->context->tenantId);
        self::assertArrayNotHasKey('current_target_id', get_object_vars($authentication->context));
        self::assertArrayNotHasKey('current_subject_id', get_object_vars($authentication->context));
        self::assertArrayNotHasKey('refresh_token', $authentication->responseData());

        $access = $authentication->tokens->access->expose();
        $refresh = $authentication->tokens->refresh->expose();
        $storedHashes = $this->query(<<<'SQL'
SELECT token_hash FROM pa_tenant_session_token ORDER BY id
SQL)->fetchAll();
        $serialized = json_encode($storedHashes, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($access, $serialized);
        self::assertStringNotContainsString($refresh, $serialized);
        self::assertStringContainsString(hash('sha256', $access), $serialized);
        self::assertStringContainsString(hash('sha256', $refresh), $serialized);

        $cookie = TenantRefreshCookie::issue($this->auth->client(), $authentication->tokens->refresh);
        self::assertStringStartsWith('__Host-pa_tenant_refresh_admin-web=', $cookie);
        self::assertStringContainsString('Secure', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
        self::assertStringContainsString('Path=/', $cookie);
        self::assertStringNotContainsString('Domain=', $cookie);

        $untrustedOrigin = $this->captureAuthError(fn() => (new TenantAuthEndpoint($this->auth))->refresh(
            $refresh,
            false,
            '127.0.0.1',
            'Test Agent',
            'request-untrusted-origin',
        ));
        self::assertSame('AUTH_TOKEN_INVALID', $untrustedOrigin->errorCode);
    }

    public function testChallengeIsSingleUseAndCannotSelectAnotherAccountsTenant(): void
    {
        $selection = $this->login();
        self::assertInstanceOf(TenantSelectionRequired::class, $selection);
        $this->selectAlpha($selection);

        $error = $this->captureAuthError(fn() => $this->auth->selectTenant(
            $selection->challenge->expose(),
            $this->alphaTenantId,
            '127.0.0.1',
            null,
            'request-replay',
        ));
        self::assertSame('AUTH_CHALLENGE_USED', $error->errorCode);
    }

    public function testRegisteredClientsUseIndependentChallengesSessionsTokensAndCookies(): void
    {
        $registry = new TenantClientRegistry(['single-store-web', 'multi-store-web']);
        $single = $this->authServiceForClient($registry, 'single-store-web');
        $multi = $this->authServiceForClient($registry, 'multi-store-web');
        $singleSelection = $single->login(
            self::EMAIL,
            self::PASSWORD,
            null,
            '127.0.0.1',
            'Single Client',
            'request-single-login',
        );
        self::assertInstanceOf(TenantSelectionRequired::class, $singleSelection);
        self::assertSame(
            'AUTH_CHALLENGE_INVALID',
            $this->captureAuthError(fn() => $multi->selectTenant(
                $singleSelection->challenge->expose(),
                $this->alphaTenantId,
                '127.0.0.1',
                'Single Client',
                'request-cross-client-challenge',
            ))->errorCode,
        );
        $singleAuth = $single->selectTenant(
            $singleSelection->challenge->expose(),
            $this->alphaTenantId,
            '127.0.0.1',
            'Single Client',
            'request-single-select',
        );
        $multiSelection = $multi->login(
            self::EMAIL,
            self::PASSWORD,
            null,
            '127.0.0.1',
            'Multi Client',
            'request-multi-login',
        );
        self::assertInstanceOf(TenantSelectionRequired::class, $multiSelection);
        $multiAuth = $multi->selectTenant(
            $multiSelection->challenge->expose(),
            $this->alphaTenantId,
            '127.0.0.1',
            'Multi Client',
            'request-multi-select',
        );

        self::assertSame('single-store-web', $singleAuth->context->clientKey);
        self::assertSame('multi-store-web', $multiAuth->context->clientKey);
        self::assertSame(
            ['multi-store-web', 'single-store-web'],
            $this->query('SELECT client_key FROM pa_tenant_session ORDER BY client_key')->fetchAll(PDO::FETCH_COLUMN),
        );
        self::assertNotSame(
            TenantRefreshCookie::name($single->client()),
            TenantRefreshCookie::name($multi->client()),
        );
        self::assertSame(
            'AUTH_TOKEN_INVALID',
            $this->captureAuthError(fn() => $multi->context(
                $singleAuth->tokens->access->expose(),
                'request-cross-client-access',
            ))->errorCode,
        );

        $singleRotated = $single->refresh(
            $singleAuth->tokens->refresh->expose(),
            '127.0.0.1',
            'Single Client',
            'request-single-refresh',
        );
        self::assertSame(
            $this->alphaTenantId,
            $multi->context($multiAuth->tokens->access->expose(), 'request-multi-still-valid')->tenantId,
        );
        self::assertSame(
            $this->alphaTenantId,
            $single->context($singleRotated->tokens->access->expose(), 'request-single-rotated')->tenantId,
        );
    }

    public function testChallengeRejectsChangedIpOrUserAgentWithoutBeingConsumed(): void
    {
        $selection = $this->login();
        self::assertInstanceOf(TenantSelectionRequired::class, $selection);

        self::assertSame(
            'AUTH_CHALLENGE_INVALID',
            $this->captureAuthError(fn() => $this->auth->selectTenant(
                $selection->challenge->expose(),
                $this->alphaTenantId,
                '127.0.0.2',
                'Test Agent',
                'request-challenge-changed-ip',
            ))->errorCode,
        );
        self::assertSame(
            'AUTH_CHALLENGE_INVALID',
            $this->captureAuthError(fn() => $this->auth->selectTenant(
                $selection->challenge->expose(),
                $this->alphaTenantId,
                '127.0.0.1',
                'Changed Agent',
                'request-challenge-changed-agent',
            ))->errorCode,
        );

        self::assertSame(
            $this->alphaTenantId,
            $this->selectAlpha($selection)->context->tenantId,
        );
        self::assertSame(2, (int) $this->query(<<<'SQL'
SELECT COUNT(*) FROM pa_auth_security_event
WHERE event_type = 'challenge_denied' AND reason_code = 'challenge_risk_context_changed'
SQL)->fetchColumn());
    }

    public function testAccessAndRefreshExpireAtTheExactServerBoundary(): void
    {
        $authentication = $this->selectAlpha($this->login());
        $this->clock->advance('+15 minutes');

        $error = $this->captureAuthError(fn() => $this->auth->context(
            $authentication->tokens->access->expose(),
            'request-expired-access',
        ));
        self::assertSame('AUTH_SESSION_EXPIRED', $error->errorCode);

        $rotated = $this->auth->refresh(
            $authentication->tokens->refresh->expose(),
            '127.0.0.1',
            'Test Agent',
            'request-refresh-after-access-expiry',
        );
        self::assertSame(
            $this->alphaTenantId,
            $this->auth->context(
                $rotated->tokens->access->expose(),
                'request-context-after-access-expiry',
            )->tenantId,
        );
    }

    public function testRefreshRotatesOnceAndReuseRevokesTheTokenFamily(): void
    {
        $authentication = $this->selectAlpha($this->login());
        $oldAccess = $authentication->tokens->access->expose();
        $oldRefresh = $authentication->tokens->refresh->expose();

        $rotated = $this->auth->refresh(
            $oldRefresh,
            '127.0.0.1',
            'Test Agent',
            'request-refresh',
        );
        self::assertNotSame($oldAccess, $rotated->tokens->access->expose());
        self::assertSame(
            'AUTH_TOKEN_INVALID',
            $this->captureAuthError(
                fn() => $this->auth->context($oldAccess, 'request-old-access'),
            )->errorCode,
        );
        self::assertSame(
            $this->alphaTenantId,
            $this->auth->context(
                $rotated->tokens->access->expose(),
                'request-new-access-after-late-old-access',
            )->tenantId,
        );

        $reuse = $this->captureAuthError(fn() => $this->auth->refresh(
            $oldRefresh,
            '127.0.0.1',
            'Test Agent',
            'request-reuse',
        ));
        self::assertSame('AUTH_REFRESH_REUSED', $reuse->errorCode);
        self::assertSame(
            'AUTH_TOKEN_INVALID',
            $this->captureAuthError(fn() => $this->auth->context(
                $rotated->tokens->access->expose(),
                'request-family-revoked',
            ))->errorCode,
        );
    }

    public function testConcurrentRefreshHasOneWinnerAndOneReuseDetection(): void
    {
        $authentication = $this->selectAlpha($this->login());
        $refresh = $authentication->tokens->refresh->expose();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);

        $processId = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $processId);
        if ($processId === 0) {
            fclose($sockets[0]);
            fread($sockets[1], 1);
            $outcome = $this->refreshOutcome(
                $this->authServiceForNewConnection(),
                $refresh,
                'request-refresh-child',
            );
            fwrite($sockets[1], $outcome);
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);
        fwrite($sockets[0], '1');
        $parentOutcome = $this->refreshOutcome(
            $this->auth,
            $refresh,
            'request-refresh-parent',
        );
        $childOutcome = stream_get_contents($sockets[0]);
        fclose($sockets[0]);
        pcntl_waitpid($processId, $status);
        $this->admin = $this->newConnection();
        $this->database = $this->newConnection(self::DATABASE);

        $outcomes = [$parentOutcome, $childOutcome];
        sort($outcomes);
        self::assertSame(['AUTH_REFRESH_REUSED', 'success'], $outcomes);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    public function testStateChangesInvalidateImmediatelyButAuthorizationRevisionDoesNotLogout(): void
    {
        $authentication = $this->selectAlpha($this->login());
        $access = $authentication->tokens->access->expose();
        $initialRevision = $this->auth->context(
            $access,
            'request-authz-before-change',
        )->authorizationRevision;
        $this->database->exec(<<<'SQL'
UPDATE pa_tenant_member SET authorization_revision = authorization_revision + 1 WHERE id = 1
SQL);
        self::assertSame(
            $initialRevision + 1,
            $this->auth->context($access, 'request-authz-revision')->authorizationRevision,
        );

        $this->memberships->transition(
            $this->alphaTenantId,
            $this->alphaMemberId,
            TenantMemberStatus::Suspended,
        );
        self::assertSame(
            'AUTH_MEMBER_UNAVAILABLE',
            $this->captureAuthError(
                fn() => $this->auth->context($access, 'request-member-suspended'),
            )->errorCode,
        );
    }

    public function testTenantAndAccountStatusInvalidateOnlyTheirSessions(): void
    {
        $alpha = $this->selectAlpha($this->login());
        $beta = $this->auth->login(
            self::EMAIL,
            self::PASSWORD,
            'beta-company',
            '127.0.0.1',
            null,
            'request-beta-direct',
        );
        self::assertInstanceOf(TenantAuthentication::class, $beta);

        $this->tenants->transition($this->alphaTenantId, TenantStatus::Suspended);
        self::assertSame(
            'AUTH_TENANT_UNAVAILABLE',
            $this->captureAuthError(fn() => $this->auth->context(
                $alpha->tokens->access->expose(),
                'request-alpha-suspended',
            ))->errorCode,
        );
        self::assertSame(
            $this->betaTenantId,
            $this->auth->context($beta->tokens->access->expose(), 'request-beta-valid')->tenantId,
        );

        $this->identity->transitionAccount($this->accountId, AccountStatus::Disabled);
        self::assertSame(
            'AUTH_ACCOUNT_UNAVAILABLE',
            $this->captureAuthError(fn() => $this->auth->context(
                $beta->tokens->access->expose(),
                'request-account-disabled',
            ))->errorCode,
        );
    }

    public function testCredentialChangeInvalidatesExistingSessionThroughAccountRevision(): void
    {
        $authentication = $this->selectAlpha($this->login());
        $credential = $this->identity->activeCredentialForAccount($this->accountId);
        self::assertNotNull($credential);

        $this->identity->transitionCredential($credential->id, CredentialStatus::Revoked);
        self::assertSame(
            'AUTH_ACCOUNT_UNAVAILABLE',
            $this->captureAuthError(fn() => $this->auth->context(
                $authentication->tokens->access->expose(),
                'request-credential-revoked',
            ))->errorCode,
        );
    }

    public function testLogoutLogoutAllSwitchAndAudienceSeparation(): void
    {
        $alpha = $this->selectAlpha($this->login());
        $switch = $this->auth->switchChallenge(
            $alpha->tokens->access->expose(),
            '127.0.0.1',
            null,
            'request-switch',
        );
        $beta = $this->auth->selectTenant(
            $switch->challenge->expose(),
            $this->betaTenantId,
            '127.0.0.1',
            null,
            'request-switch-select',
        );
        self::assertSame($this->betaTenantId, $beta->context->tenantId);
        self::assertSame(
            'AUTH_TOKEN_INVALID',
            $this->captureAuthError(fn() => $this->auth->context(
                $alpha->tokens->access->expose(),
                'request-old-session',
            ))->errorCode,
        );

        $this->auth->logoutAll($beta->tokens->access->expose(), 'request-logout-all');
        self::assertSame(
            'AUTH_TOKEN_INVALID',
            $this->captureAuthError(fn() => $this->auth->context(
                $beta->tokens->access->expose(),
                'request-after-logout-all',
            ))->errorCode,
        );
        self::assertSame(
            'AUTH_AUDIENCE_MISMATCH',
            $this->captureAuthError(fn() => $this->auth->context(
                'pa_pat_platform-token',
                'request-platform-audience',
            ))->errorCode,
        );
        self::assertStringContainsString('Max-Age=0', TenantRefreshCookie::clear($this->auth->client()));
    }

    private function login(): TenantSelectionRequired|TenantAuthentication
    {
        return $this->auth->login(
            self::EMAIL,
            self::PASSWORD,
            null,
            '127.0.0.1',
            'Test Agent',
            'request-login',
        );
    }

    private function selectAlpha(
        TenantSelectionRequired|TenantAuthentication $outcome,
    ): TenantAuthentication {
        self::assertInstanceOf(TenantSelectionRequired::class, $outcome);

        return $this->auth->selectTenant(
            $outcome->challenge->expose(),
            $this->alphaTenantId,
            '127.0.0.1',
            'Test Agent',
            'request-select-alpha',
        );
    }

    private function captureAuthError(callable $operation): AuthException
    {
        try {
            $operation();
        } catch (AuthException $exception) {
            self::addToAssertionCount(1);

            return $exception;
        }

        self::fail('Expected authentication operation to fail.');
    }

    private function authServiceForNewConnection(): TenantAuthService
    {
        $pdo = $this->newConnection(self::DATABASE);

        return new TenantAuthService(
            new PdoTransactionManager($pdo),
            new PdoTenantAuthRepository($pdo),
            new PasswordHasher(),
            new MutableClock($this->clock->now()),
            new TokenIssuer(),
            'test-identifier-hmac-secret-at-least-32-bytes',
        );
    }

    private function authServiceForClient(TenantClientRegistry $registry, string $clientKey): TenantAuthService
    {
        return new TenantAuthService(
            new PdoTransactionManager($this->database),
            new PdoTenantAuthRepository($this->database),
            new PasswordHasher(),
            $this->clock,
            new TokenIssuer(),
            'test-identifier-hmac-secret-at-least-32-bytes',
            $registry,
            $clientKey,
        );
    }

    private function newConnection(?string $database = null): PDO
    {
        return new PDO(
            'mysql:host=127.0.0.1;port=' . (getenv('MYSQL_PORT') ?: '3306')
            . ($database === null ? '' : ";dbname={$database}")
            . ';charset=utf8mb4',
            'root',
            getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function refreshOutcome(
        TenantAuthService $service,
        string $refreshToken,
        string $requestId,
    ): string {
        try {
            $service->refresh($refreshToken, '127.0.0.1', null, $requestId);

            return 'success';
        } catch (AuthException $exception) {
            return $exception->errorCode;
        }
    }
}
