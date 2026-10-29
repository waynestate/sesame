<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Waynestate\Playbook\Playbook;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Drivers\Oidc\OidcOptions;
use Waynestate\Sesame\Drivers\Oidc\PendingLogins;
use Waynestate\Sesame\Drivers\OidcDriver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Mfa;
use Waynestate\Sesame\MfaResult;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Support\Setting;
use Waynestate\Sesame\Tests\Support\ArrayCache;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\FakeOpenIdProvider;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

/**
 * The oidc driver, end to end against an in-process OpenID provider.
 *
 * The provider (FakeOpenIdProvider) answers discovery, the key set and the
 * token endpoint through a Guzzle handler, checks the PKCE verifier and the
 * client's credentials, and signs ID tokens with a locally generated RSA
 * key. Nothing touches the network, and every host is example.test.
 */
final class OidcDriverTest extends TestCase
{
    private FakeClock $clock;

    private FakeOpenIdProvider $op;

    private ArrayCache $cache;

    private EventLog $log;

    private RecordingSession $session;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->op = new FakeOpenIdProvider($this->clock);
        $this->cache = new ArrayCache($this->clock);
        $this->log = new EventLog();
        $this->session = new RecordingSession($this->log);
    }

    protected function tearDown(): void
    {
        Playbook::reset();
        putenv('SESAME_OIDC_CLIENT_SECRET');
    }

    public function testNameIsOidc(): void
    {
        self::assertSame('oidc', $this->driver()->name());
    }

    public function testTheHappyPathSignsTheUserInEndToEnd(): void
    {
        $driver = $this->driver();

        $step = $this->signIn($driver);

        $identity = $step->identity;
        self::assertSame('ab1234', $identity->username);
        self::assertSame('oidc', $identity->driver);
        self::assertSame(['employee', 'staff'], $identity->attributes['roles']);
        self::assertSame(['jane.doe@example.test'], $identity->attributes['email']);
        self::assertSame(['subject-123'], $identity->attributes['sub']);
        self::assertSame(['object-456'], $identity->attributes['oid']);
        self::assertNotNull($identity->authTime);
        self::assertSame($this->clock->now()->getTimestamp() - 120, $identity->authTime->getTimestamp());
        self::assertSame($identity->authTime->getTimestamp(), $identity->authenticatedAt->getTimestamp());
        self::assertSame(1, $this->op->tokenRequests);
        self::assertSame(1, $this->op->discoveryFetches);
        self::assertSame(1, $this->op->jwksFetches);
        self::assertNull($this->session->store[PendingLogins::SESSION_KEY] ?? null, 'The pending sign-in is spent.');
    }

    public function testTheChosenClaimsPassThroughToTheResolver(): void
    {
        $identity = $this->signIn($this->driver())->identity;

        self::assertSame(['employee', 'staff'], $identity->attributes['wsuRoles']);
        self::assertSame(['ab1234'], $identity->attributes['accessid']);
    }

    public function testTheClaimListIsConfigurable(): void
    {
        $this->op->claimOverrides = ['department' => 'Enterprise Applications'];

        $identity = $this->signIn($this->driver(['claims' => 'department']))->identity;

        self::assertSame(['Enterprise Applications'], $identity->attributes['department']);
        self::assertArrayNotHasKey('wsuRoles', $identity->attributes);
        self::assertArrayNotHasKey('accessid', $identity->attributes);
    }

    public function testTheFacadeRegeneratesTheSessionAndStoresTheUser(): void
    {
        $driver = $this->driver();
        $resolver = new RecordingResolver();
        $config = new Config(driver: 'oidc', environment: 'production');
        $sesame = new Sesame($driver, $resolver, $this->session, $config, $this->clock);

        $redirect = $sesame->login($this->request());
        self::assertInstanceOf(Redirect::class, $redirect);

        $callback = $this->op->authorize($redirect->url);
        $user = $sesame->login($this->request($callback));

        self::assertNotNull($user);
        self::assertContains('regenerate', $this->log->events);
        self::assertInstanceOf(Identity::class, $this->session->store['sesame.identity']);
        self::assertSame([], $this->session->store['sesame.identity']->attributes, 'Raw claims are not kept in the session.');
    }

    public function testTheAuthorizationRequestUsesPkceS256StateAndNonce(): void
    {
        $query = $this->startQuery($this->driver());

        self::assertSame('S256', $query['code_challenge_method']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['code_challenge']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['state']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['nonce']);
        self::assertSame('query', $query['response_mode']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('openid profile email', $query['scope']);
        self::assertSame(FakeOpenIdProvider::REDIRECT_URI, $query['redirect_uri']);
        self::assertSame(FakeOpenIdProvider::CLIENT_ID, $query['client_id']);
        self::assertArrayNotHasKey('client_secret', $query);
    }

    public function testNoPromptOrMaxAgeIsSentByDefaultSoSsoStaysSilent(): void
    {
        $query = $this->startQuery($this->driver());

        foreach (['prompt', 'max_age', 'acr_values', 'claims', 'approval_prompt'] as $name) {
            self::assertArrayNotHasKey($name, $query, sprintf('The default sign-in must not send %s.', $name));
        }
    }

    public function testAStepUpAddsTheConfiguredParameters(): void
    {
        $driver = $this->driver(['step_up' => [
            'prompt' => 'login',
            'max_age' => 300,
            'acr_values' => 'urn:example:mfa',
            'claims' => '{"id_token":{"acrs":{"essential":true,"value":"c1"}}}',
        ]]);

        $query = $this->startQuery($driver, ['step_up' => '1']);

        self::assertSame('login', $query['prompt']);
        self::assertSame('300', $query['max_age']);
        self::assertSame('urn:example:mfa', $query['acr_values']);
        self::assertSame('{"id_token":{"acrs":{"essential":true,"value":"c1"}}}', $query['claims']);
    }

    public function testAStepUpRequestCannotChooseItsOwnParameters(): void
    {
        $query = $this->startQuery($this->driver(), ['step_up' => '1', 'prompt' => 'none', 'max_age' => '99999', 'acr_values' => 'x']);

        self::assertSame('login', $query['prompt']);
        self::assertArrayNotHasKey('max_age', $query);
        self::assertArrayNotHasKey('acr_values', $query);
    }

    public function testAStepUpWithAFreshSignInIsAccepted(): void
    {
        $driver = $this->driver(['step_up' => ['max_age' => 300]]);
        $this->op->claimOverrides = ['auth_time' => $this->clock->now()->getTimestamp(), 'acrs' => ['c1', 'bogus']];

        $step = $this->signIn($driver, ['step_up' => '1']);

        self::assertSame(['c1'], $step->identity->authContexts());
    }

    public function testAStepUpThatReusesAnOldSignInIsRejected(): void
    {
        $driver = $this->driver();
        $this->op->claimOverrides = ['auth_time' => $this->clock->now()->getTimestamp() - 3600];

        $this->assertRejected('oidc.step_up', fn(): mixed => $this->signIn($driver, ['step_up' => '1']));
    }

    public function testAStepUpOlderThanMaxAgeIsRejected(): void
    {
        $driver = $this->driver(['step_up' => ['prompt' => null, 'max_age' => 60]]);
        $this->op->claimOverrides = ['auth_time' => $this->clock->now()->getTimestamp() - 600];

        $this->assertRejected('oidc.step_up', fn(): mixed => $this->signIn($driver, ['step_up' => '1']));
    }

    public function testAStepUpWithoutAuthTimeIsRejected(): void
    {
        $driver = $this->driver();
        $this->op->claimRemovals = ['auth_time'];

        $this->assertRejected('oidc.step_up', fn(): mixed => $this->signIn($driver, ['step_up' => '1']));
    }

    public function testABadStateIsRejectedBeforeTheCodeIsUsed(): void
    {
        $driver = $this->driver();
        $callback = $this->op->authorize($this->start($driver)->url);
        $callback['state'] = 'not-the-state-we-sent';

        $this->assertRejected('oidc.state', fn(): mixed => $driver->login($this->request($callback)));
        self::assertSame(0, $this->op->tokenRequests);
    }

    public function testAMissingStateIsRejected(): void
    {
        $this->assertRejected('oidc.state', fn(): mixed => $this->driver()->login($this->request(['code' => 'abc'])));
    }

    public function testAReplayedStateIsRejected(): void
    {
        $driver = $this->driver();
        $callback = $this->op->authorize($this->start($driver)->url);

        self::assertInstanceOf(Authenticated::class, $driver->login($this->request($callback)));
        $this->assertRejected('oidc.state', fn(): mixed => $driver->login($this->request($callback)));
        self::assertSame(1, $this->op->tokenRequests);
    }

    public function testTheStateIsSpentEvenWhenTheCallbackFails(): void
    {
        $driver = $this->driver();
        $this->op->claimOverrides = ['nonce' => 'wrong'];
        $callback = $this->op->authorize($this->start($driver)->url);

        $this->assertRejected('oidc.nonce', fn(): mixed => $driver->login($this->request($callback)));
        $this->assertRejected('oidc.state', fn(): mixed => $driver->login($this->request($callback)));
    }

    public function testAnExpiredStateIsRejected(): void
    {
        $driver = $this->driver();
        $callback = $this->op->authorize($this->start($driver)->url);
        $this->clock->advance(601);

        $this->assertRejected('oidc.state', fn(): mixed => $driver->login($this->request($callback)));
    }

    public function testTheStateIsBoundToTheBrowserSession(): void
    {
        $driver = $this->driver();
        $callback = $this->op->authorize($this->start($driver)->url);

        // Another browser: a different session that never started a sign-in.
        $other = $this->driver(session: new RecordingSession(new EventLog()));

        $this->assertRejected('oidc.state', fn(): mixed => $other->login($this->request($callback)));
    }

    public function testABadNonceIsRejected(): void
    {
        $this->op->claimOverrides = ['nonce' => 'a-nonce-someone-else-got'];

        $this->assertRejected('oidc.nonce', fn(): mixed => $this->signIn($this->driver()));
    }

    public function testAMissingNonceIsRejected(): void
    {
        $this->op->claimRemovals = ['nonce'];

        $this->assertRejected('oidc.nonce', fn(): mixed => $this->signIn($this->driver()));
    }

    public function testAWrongIssuerIsRejected(): void
    {
        $this->op->claimOverrides = ['iss' => 'https://login.example.test/another-tenant/v2.0'];

        $this->assertRejected('oidc.issuer', fn(): mixed => $this->signIn($this->driver()));
    }

    public function testAWrongAudienceIsRejected(): void
    {
        $this->op->claimOverrides = ['aud' => 'some-other-client'];

        $this->assertRejected('oidc.audience', fn(): mixed => $this->signIn($this->driver()));
    }

    public function testAWrongAuthorizedPartyIsRejected(): void
    {
        $this->op->claimOverrides = ['azp' => 'some-other-client'];

        $this->assertRejected('oidc.azp', fn(): mixed => $this->signIn($this->driver()));
    }

    public function testSeveralAudiencesNeedAnAuthorizedParty(): void
    {
        $this->op->claimOverrides = ['aud' => [FakeOpenIdProvider::CLIENT_ID, 'another-client']];

        $this->assertRejected('oidc.azp', fn(): mixed => $this->signIn($this->driver()));
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->op->claimOverrides = ['iat' => $now - 7200, 'nbf' => $now - 7200, 'exp' => $now - 61];

        $this->assertRejected('oidc.expired', fn(): mixed => $this->signIn($this->driver()));
    }

    public function testATokenWithinTheLeewayIsAccepted(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->op->claimOverrides = ['exp' => $now - 30];

        self::assertInstanceOf(Authenticated::class, $this->signIn($this->driver()));
    }

    public function testATokenIssuedInTheFutureIsRejected(): void
    {
        $this->op->claimOverrides = ['iat' => $this->clock->now()->getTimestamp() + 600];

        $this->assertRejected('oidc.not_yet_valid', fn(): mixed => $this->signIn($this->driver()));
    }

    public function testATokenWithoutIatIsRejected(): void
    {
        $this->op->claimRemovals = ['iat'];

        $this->assertRejected('oidc.missing_claim', fn(): mixed => $this->signIn($this->driver()));
    }

    public function testAlgNoneIsRejectedWithoutFetchingKeys(): void
    {
        $this->op->signing = 'none';

        $this->assertRejected('oidc.bad_algorithm', fn(): mixed => $this->signIn($this->driver()));
        self::assertSame(0, $this->op->jwksFetches);
    }

    public function testHs256SignedWithThePublicKeyIsRejected(): void
    {
        $this->op->signing = 'hs256-with-public-key';

        $this->assertRejected('oidc.bad_algorithm', fn(): mixed => $this->signIn($this->driver()));
        self::assertSame(0, $this->op->jwksFetches);
    }

    public function testAnAlgorithmOffTheAllowlistIsRejected(): void
    {
        $this->op->headerOverrides = ['alg' => 'RS512'];

        $this->assertRejected('oidc.bad_algorithm', fn(): mixed => $this->signIn($this->driver()));
    }

    public function testATamperedSignatureIsRejected(): void
    {
        $this->op->tokenResponse = [
            'token_type' => 'Bearer',
            'access_token' => 'x',
            'id_token' => $this->tamperedToken(),
        ];

        $this->assertRejected('oidc.signature', fn(): mixed => $this->signIn($this->driver()));
    }

    public function testAnUnknownKidTriggersOneKeySetRefreshThenFails(): void
    {
        $driver = $this->driver();
        $this->signIn($driver);
        self::assertSame(1, $this->op->jwksFetches);

        // Past the first fetch's cooldown, so the unknown kid may refresh.
        $this->clock->advance(61);
        $this->op->headerOverrides = ['kid' => 'a-key-nobody-published'];
        $this->assertRejected('oidc.unknown_key', fn(): mixed => $this->signIn($driver));
        self::assertSame(2, $this->op->jwksFetches, 'One refresh for the unknown kid.');

        $this->assertRejected('oidc.unknown_key', fn(): mixed => $this->signIn($driver));
        self::assertSame(2, $this->op->jwksFetches, 'No second refresh within the cooldown.');
    }

    public function testARotatedKeyIsPickedUpByTheRefresh(): void
    {
        $driver = $this->driver();
        $this->op->publishedKid = 'old-kid';
        $this->op->headerOverrides = ['kid' => 'old-kid'];
        $this->signIn($driver);

        $this->clock->advance(61);
        $this->op->publishedKid = 'new-kid';
        $this->op->headerOverrides = ['kid' => 'new-kid'];

        self::assertInstanceOf(Authenticated::class, $this->signIn($driver));
        self::assertSame(2, $this->op->jwksFetches);
    }

    public function testTheDiscoveryDocumentIsCached(): void
    {
        $driver = $this->driver();
        $this->signIn($driver);
        $this->signIn($driver);

        self::assertSame(1, $this->op->discoveryFetches);
        self::assertSame(1, $this->op->jwksFetches);
    }

    public function testDiscoveryWithAnotherIssuerIsRejected(): void
    {
        $this->op->discoveryDocument = ['issuer' => 'https://login.example.test/evil/v2.0'] + $this->op->discovery();

        $this->assertRejected('oidc.discovery_issuer', fn(): mixed => $this->start($this->driver()));
    }

    public function testADiscoveredEndpointMustBeHttps(): void
    {
        $this->op->discoveryDocument = ['authorization_endpoint' => 'http://login.example.test/authorize'] + $this->op->discovery();

        $this->assertRejected('oidc.discovery', fn(): mixed => $this->start($this->driver()));
    }

    public function testConfiguredEndpointsSkipDiscovery(): void
    {
        $driver = $this->driver([
            'authorization_endpoint' => FakeOpenIdProvider::AUTHORIZE_URL,
            'token_endpoint' => FakeOpenIdProvider::TOKEN_URL,
            'jwks_uri' => FakeOpenIdProvider::JWKS_URL,
        ]);

        self::assertInstanceOf(Authenticated::class, $this->signIn($driver));
        self::assertSame(0, $this->op->discoveryFetches);
    }

    public function testAnUnreachableProviderFailsClosed(): void
    {
        $driver = $this->driver(http: FakeOpenIdProvider::brokenClient());

        $this->assertRejected('oidc.discovery_unavailable', fn(): mixed => $this->start($driver));
    }

    public function testAProviderErrorIsReportedByCodeOnlyAndSpendsTheState(): void
    {
        $driver = $this->driver();
        $callback = $this->op->authorize($this->start($driver)->url);
        $error = ['state' => $callback['state'], 'error' => 'access_denied', 'error_description' => '<script>alert(1)</script>'];

        try {
            $driver->login($this->request($error));
            self::fail('Expected AuthenticationFailed.');
        } catch (AuthenticationFailed $e) {
            self::assertStringContainsString('oidc.provider_error: access_denied', $e->getMessage());
            self::assertStringNotContainsString('script', $e->getMessage());
        }

        $this->assertRejected('oidc.state', fn(): mixed => $driver->login($this->request($callback)));
    }

    public function testATokenEndpointErrorIsReportedByCode(): void
    {
        $driver = $this->driver();
        $callback = $this->op->authorize($this->start($driver)->url);
        $callback['code'] = 'a-code-the-provider-never-issued';

        $this->assertRejected('oidc.token_exchange: invalid_grant', fn(): mixed => $driver->login($this->request($callback)));
    }

    public function testATokenResponseWithoutAnIdTokenIsRejected(): void
    {
        $this->op->tokenResponse = ['token_type' => 'Bearer', 'access_token' => 'x'];

        $this->assertRejected('oidc.missing_id_token', fn(): mixed => $this->signIn($this->driver()));
    }

    public function testClientSecretBasicWorksEndToEnd(): void
    {
        $driver = $this->driver(['client_auth' => 'client_secret_basic']);

        self::assertInstanceOf(Authenticated::class, $this->signIn($driver));

        $token = $this->tokenRequest();
        self::assertStringStartsWith('Basic ', $token->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('client_secret', (string) $token->getBody());
    }

    public function testClientSecretPostSendsTheSecretInTheBodyOnly(): void
    {
        self::assertInstanceOf(Authenticated::class, $this->signIn($this->driver()));

        $token = $this->tokenRequest();
        self::assertSame('', $token->getHeaderLine('Authorization'));
        self::assertStringNotContainsString(rawurlencode(FakeOpenIdProvider::CLIENT_SECRET), (string) $token->getUri());
        parse_str((string) $token->getBody(), $form);
        self::assertSame(FakeOpenIdProvider::CLIENT_SECRET, $form['client_secret']);
        self::assertArrayHasKey('code_verifier', $form);
    }

    public function testAWrongSecretIsRejectedWithoutShowingIt(): void
    {
        $driver = $this->driver(['client_secret' => 'the-wrong-secret-value']);

        try {
            $this->signIn($driver);
            self::fail('Expected AuthenticationFailed.');
        } catch (AuthenticationFailed $e) {
            self::assertStringContainsString('oidc.token_exchange: invalid_client', $e->getMessage());
            self::assertStringNotContainsString('the-wrong-secret-value', $e->getMessage());
            self::assertStringNotContainsString('the-wrong-secret-value', $e->getTraceAsString());
            self::assertNull($e->getPrevious());
        }
    }

    public function testTheSecretNeverAppearsInExceptionsOrDumps(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $secret = FakeOpenIdProvider::CLIENT_SECRET;

        try {
            $failures = [
                fn(): mixed => $this->signIn($this->driver(http: FakeOpenIdProvider::brokenClient())),
                function (): mixed {
                    $this->op->claimOverrides = ['nonce' => 'x'];

                    return $this->signIn($this->driver());
                },
                function (): mixed {
                    $driver = $this->driver();
                    $callback = $this->op->authorize($this->start($driver)->url);
                    $callback['code'] = 'never-issued';

                    return $driver->login($this->request($callback));
                },
                fn(): mixed => OidcOptions::fromArray(['issuer' => 'not a url', 'client_id' => 'x', 'client_secret' => $secret, 'redirect_uri' => FakeOpenIdProvider::REDIRECT_URI]),
                fn(): mixed => OidcOptions::fromArray(['issuer' => FakeOpenIdProvider::ISSUER, 'client_id' => 'x', 'client_secret' => $secret, 'redirect_uri' => FakeOpenIdProvider::REDIRECT_URI, 'algorithms' => ['HS256']]),
            ];

            foreach ($failures as $i => $failure) {
                try {
                    $failure();
                    self::fail(sprintf('Failure %d did not fail.', $i));
                } catch (AuthenticationFailed|InvalidConfig $e) {
                    for ($t = $e; $t !== null; $t = $t->getPrevious()) {
                        self::assertStringNotContainsString($secret, $t->getMessage(), (string) $i);
                        self::assertStringNotContainsString($secret, $t->getTraceAsString(), (string) $i);
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }

        $driver = $this->driver();
        $config = Config::fromArray(['driver' => 'oidc', 'environment' => 'production', 'oidc' => $this->settings()]);

        self::assertStringNotContainsString($secret, print_r($driver, true));
        self::assertStringNotContainsString($secret, print_r($config, true));
        self::assertStringContainsString('[redacted]', print_r($config, true));
        ob_start();
        var_dump($driver, $config);
        self::assertStringNotContainsString($secret, (string) ob_get_clean());
    }

    public function testTheSecretIsNeverStoredInTheSession(): void
    {
        $this->signIn($this->driver());

        self::assertStringNotContainsString(FakeOpenIdProvider::CLIENT_SECRET, serialize($this->session->store));
    }

    public function testAForeignReturnToIsDropped(): void
    {
        foreach ([
            'https://evil.example.test/steal',
            'https://app.example.test@evil.example.test/steal',
            'http://app.example.test/insecure',
            '//evil.example.test/steal',
            '/relative/path',
            "https://app.example.test/\r\nLocation: https://evil.example.test/",
            'javascript:alert(1)',
        ] as $returnTo) {
            $driver = $this->driver();
            $this->signIn($driver, ['return_to' => $returnTo]);

            self::assertNull($driver->returnTo(), $returnTo);
        }
    }

    public function testASameHostReturnToIsKept(): void
    {
        $driver = $this->driver();
        $this->signIn($driver, ['return_to' => 'https://app.example.test/reports?id=7']);

        self::assertSame('https://app.example.test/reports?id=7', $driver->returnTo());
    }

    public function testTheFacadeExposesTheReturnTo(): void
    {
        $driver = $this->driver();
        $sesame = new Sesame($driver, new RecordingResolver(), $this->session, new Config(driver: 'oidc', environment: 'production'), $this->clock);

        $redirect = $sesame->login($this->request(['return_to' => 'https://app.example.test/after']));
        self::assertInstanceOf(Redirect::class, $redirect);
        $sesame->login($this->request($this->op->authorize($redirect->url)));

        self::assertSame('https://app.example.test/after', $sesame->returnTo());
    }

    public function testTheRolesClaimIsFoundUnderACustomClaimNamespace(): void
    {
        $this->op->claimRemovals = ['wsuRoles'];
        $this->op->claimOverrides = ['http://schemas.example.test/claims/wsuRoles' => ['employee']];

        $identity = $this->signIn($this->driver())->identity;

        self::assertSame(['employee'], $identity->attributes['roles']);
        self::assertSame(['employee'], $identity->attributes['wsuRoles']);
    }

    public function testTheRolesClaimIsFoundUnderADottedNamespace(): void
    {
        $this->op->claimRemovals = ['wsuRoles'];
        $this->op->claimOverrides = ['extn.wsuRoles' => 'employee'];

        self::assertSame(['employee'], $this->signIn($this->driver())->identity->attributes['roles']);
    }

    public function testAnAmbiguousNamespacedRolesClaimGrantsNothing(): void
    {
        $this->op->claimRemovals = ['wsuRoles'];
        $this->op->claimOverrides = [
            'http://schemas.example.test/claims/wsuRoles' => ['employee'],
            'extn.wsuRoles' => ['admin'],
        ];

        self::assertArrayNotHasKey('roles', $this->signIn($this->driver())->identity->attributes);
    }

    public function testTheRolesClaimNameIsConfigurable(): void
    {
        $this->op->claimOverrides = ['groupsForApp' => ['a', 'b', 7]];

        $identity = $this->signIn($this->driver(['roles_claim' => 'groupsForApp']))->identity;

        self::assertSame(['a', 'b'], $identity->attributes['roles']);
    }

    public function testTheUsernameFallsBackToThePreferredUsernamePrefixOnlyWhenOptedIn(): void
    {
        $this->op->claimRemovals = ['accessid'];

        self::assertSame('ab1234', $this->signIn($this->driver(['username_fallback' => true]))->identity->username);
    }

    public function testTheUsernameFallbackIsOffByDefault(): void
    {
        self::assertFalse(OidcOptions::fromArray($this->settings())->usernameFallback);
        $this->op->claimRemovals = ['accessid'];

        try {
            $this->signIn($this->driver());
            self::fail('A sign-in without the username claim must fail by default.');
        } catch (AuthenticationFailed $e) {
            self::assertStringContainsString('oidc.missing_claim', $e->getMessage());
            self::assertStringContainsString('SESAME_OIDC_USERNAME_FALLBACK', $e->getMessage());
            self::assertStringContainsString('SESAME_OIDC_USERNAME_CLAIM', $e->getMessage());
        }
    }

    public function testWithTheFallbackOffAMissingUsernameClaimIsRejected(): void
    {
        $this->op->claimRemovals = ['accessid'];

        $this->assertRejected('oidc.missing_claim', fn(): mixed => $this->signIn($this->driver(['username_fallback' => false])));
    }

    public function testIssuerSubjectTenantAndObjectIdReachTheResolver(): void
    {
        $identity = $this->signIn($this->driver())->identity;

        self::assertSame([FakeOpenIdProvider::ISSUER], $identity->attributes['iss']);
        self::assertSame(['subject-123'], $identity->attributes['sub']);
        self::assertSame(['tenant-for-tests'], $identity->attributes['tid']);
        self::assertSame(['object-456'], $identity->attributes['oid']);
    }

    public function testTheUsernameClaimIsConfigurable(): void
    {
        $identity = $this->signIn($this->driver(['username_claim' => 'oid']))->identity;

        self::assertSame('object-456', $identity->username);
    }

    public function testWithoutAmrTheMfaCheckFailsClosed(): void
    {
        $identity = $this->signIn($this->driver())->identity;

        self::assertSame([], $identity->amr);
        self::assertSame(MfaResult::NO_MFA_METHOD, Mfa::satisfied($identity, 28800, $this->clock->now())->reason);
    }

    public function testAmrFromTheTokenSatisfiesTheMfaCheck(): void
    {
        $this->op->claimOverrides = ['amr' => ['pwd', 'mfa']];

        $identity = $this->signIn($this->driver())->identity;

        self::assertTrue(Mfa::satisfied($identity, 28800, $this->clock->now())->satisfied);
    }

    public function testAssumeMfaAddsMfaButNeverInventsAuthTime(): void
    {
        $this->op->claimRemovals = ['auth_time'];

        $identity = $this->signIn($this->driver(['assume_mfa' => true]))->identity;

        self::assertSame(['mfa'], $identity->amr);
        self::assertNull($identity->authTime);
        self::assertSame(MfaResult::NO_AUTH_TIME, Mfa::satisfied($identity, 28800, $this->clock->now())->reason);
    }

    public function testAFutureAuthTimeDoesNotMoveTheSignInTime(): void
    {
        $this->op->claimOverrides = ['auth_time' => $this->clock->now()->getTimestamp() + 3600];

        $identity = $this->signIn($this->driver())->identity;

        self::assertEquals($this->clock->now(), $identity->authenticatedAt);
        self::assertSame(MfaResult::FUTURE_AUTH_TIME, Mfa::satisfied($identity, 28800, $this->clock->now())->reason);
    }

    public function testLogoutWithoutAPostLogoutUriStaysLocal(): void
    {
        self::assertNull($this->driver()->logoutUrl('https://app.example.test/'));
    }

    public function testLogoutGoesThroughTheEndSessionEndpointWhenConfigured(): void
    {
        $url = $this->driver(['post_logout_redirect_uri' => 'https://app.example.test/signed-out'])->logoutUrl('https://evil.example.test/');

        self::assertSame(
            FakeOpenIdProvider::END_SESSION_URL . '?client_id=' . FakeOpenIdProvider::CLIENT_ID . '&post_logout_redirect_uri=' . rawurlencode('https://app.example.test/signed-out'),
            $url,
        );
    }

    public function testLogoutFallsBackToLocalWhenDiscoveryFails(): void
    {
        $driver = $this->driver(['post_logout_redirect_uri' => 'https://app.example.test/signed-out'], http: FakeOpenIdProvider::brokenClient());

        self::assertNull($driver->logoutUrl('https://app.example.test/'));
    }

    public function testTheFacadeLogoutClearsTheSessionAndKeepsTheReturnToOnHost(): void
    {
        $driver = $this->driver();
        $sesame = new Sesame($driver, new RecordingResolver(), $this->session, new Config(driver: 'oidc', environment: 'production'), $this->clock);

        self::assertNull($sesame->logout('https://app.example.test/'));
        self::assertContains('invalidate', $this->log->events);
        self::assertSame(FakeOpenIdProvider::REDIRECT_URI, $sesame->safeReturnTo('https://evil.example.test/'));
        self::assertSame('https://app.example.test/bye', $sesame->safeReturnTo('https://app.example.test/bye'));
    }

    public function testAMissingLibraryGivesAClearError(): void
    {
        try {
            OidcDriver::assertLibraryInstalled('League\\OAuth2\\Client\\Provider\\NotInstalled');
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString('composer require league/oauth2-client', $e->getMessage());
        }

        OidcDriver::assertLibraryInstalled();
        $this->addToAssertionCount(1);
    }

    public function testTheFactoryBuildsTheDriverAndNeedsTheSession(): void
    {
        $config = Config::fromArray(['driver' => 'oidc', 'environment' => 'production', 'oidc' => $this->settings()]);
        $http = $this->op->client();
        $factory = new Psr17Factory();

        self::assertInstanceOf(OidcDriver::class, DriverFactory::make($config, $http, $factory, $this->clock, null, $this->cache, $this->session));

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('session');
        DriverFactory::make($config, $http, $factory, $this->clock, null, $this->cache);
    }

    public function testTheDriverWorksWithoutACache(): void
    {
        $driver = new OidcDriver(OidcOptions::fromArray($this->settings()), $this->op->client(), new Psr17Factory(), $this->session, null, $this->clock);

        self::assertInstanceOf(Authenticated::class, $this->signIn($driver));
    }

    public function testTheSecretComesFromTheEnvironmentNeverThePlaybook(): void
    {
        $settings = $this->settings();
        unset($settings['client_secret']);
        Playbook::setValues(['SESAME_OIDC_CLIENT_SECRET' => 'from-the-playbook']);

        self::assertNull(Setting::get('SESAME_OIDC_CLIENT_SECRET'));

        try {
            OidcOptions::fromArray($settings);
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString('SESAME_OIDC_CLIENT_SECRET', $e->getMessage());
            self::assertStringNotContainsString('from-the-playbook', $e->getMessage());
        }

        putenv('SESAME_OIDC_CLIENT_SECRET=from-the-environment');
        self::assertSame('from-the-environment', OidcOptions::fromArray($settings)->clientSecret());
    }

    public function testRequiredSettingsAreNamed(): void
    {
        foreach (['issuer' => 'SESAME_OIDC_ISSUER', 'client_id' => 'SESAME_OIDC_CLIENT_ID', 'redirect_uri' => 'SESAME_OIDC_REDIRECT_URI'] as $key => $setting) {
            $settings = $this->settings();
            unset($settings[$key]);

            try {
                OidcOptions::fromArray($settings);
                self::fail('Expected InvalidConfig for ' . $key);
            } catch (InvalidConfig $e) {
                self::assertStringContainsString($setting, $e->getMessage());
            }
        }
    }

    public function testUnsafeAlgorithmsCannotBeConfigured(): void
    {
        foreach (['none', 'HS256', 'ES256'] as $alg) {
            try {
                OidcOptions::fromArray(['algorithms' => [$alg]] + $this->settings());
                self::fail('Expected InvalidConfig for ' . $alg);
            } catch (InvalidConfig $e) {
                self::assertStringContainsString('oidc.algorithms', $e->getMessage());
            }
        }
    }

    public function testOpenidIsAlwaysRequested(): void
    {
        self::assertSame(['openid', 'profile'], OidcOptions::fromArray(['scopes' => 'profile'] + $this->settings())->scopes);
    }

    public function testTheRedirectUriMustBeHttpsOrLocalhost(): void
    {
        self::assertSame('http://localhost:18901/callback', OidcOptions::fromArray(['redirect_uri' => 'http://localhost:18901/callback'] + $this->settings())->redirectUri);

        $this->expectException(InvalidConfig::class);
        OidcOptions::fromArray(['redirect_uri' => 'http://app.example.test/callback'] + $this->settings());
    }

    public function testTheIssuerFallsBackToTheOauth2Issuer(): void
    {
        putenv('SESAME_OAUTH2_ISSUER=https://login.example.test/from-oauth2/v2.0');

        try {
            $config = require __DIR__ . '/../config/sesame.php';
            self::assertIsArray($config);
            self::assertSame('https://login.example.test/from-oauth2/v2.0', $config['oidc']['issuer']);

            putenv('SESAME_OIDC_ISSUER=https://login.example.test/from-oidc/v2.0');
            $config = require __DIR__ . '/../config/sesame.php';
            self::assertIsArray($config);
            self::assertSame('https://login.example.test/from-oidc/v2.0', $config['oidc']['issuer']);
        } finally {
            putenv('SESAME_OAUTH2_ISSUER');
            putenv('SESAME_OIDC_ISSUER');
        }
    }

    /**
     * Start, authorize at the fake provider, and finish the sign-in.
     *
     * @param array<string, string> $startQuery
     */
    private function signIn(OidcDriver $driver, array $startQuery = []): Authenticated
    {
        $callback = $this->op->authorize($this->start($driver, $startQuery)->url);
        $step = $driver->login($this->request($callback));

        self::assertInstanceOf(Authenticated::class, $step);

        return $step;
    }

    /**
     * @param array<string, string> $query
     */
    private function start(OidcDriver $driver, array $query = []): Redirect
    {
        $step = $driver->login($this->request($query));
        self::assertInstanceOf(Redirect::class, $step);

        return $step;
    }

    /**
     * The authorization request's query parameters.
     *
     * @param array<string, string> $query
     * @return array<string, string>
     */
    private function startQuery(OidcDriver $driver, array $query = []): array
    {
        parse_str((string) parse_url($this->start($driver, $query)->url, PHP_URL_QUERY), $params);

        /** @var array<string, string> $params */
        return $params;
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query = []): ServerRequest
    {
        return (new ServerRequest('GET', 'https://app.example.test/login/callback'))->withQueryParams($query);
    }

    private function tokenRequest(): \Psr\Http\Message\RequestInterface
    {
        foreach ($this->op->requests as $request) {
            if ((string) $request->getUri() === FakeOpenIdProvider::TOKEN_URL) {
                return $request;
            }
        }

        self::fail('No token request was sent.');
    }

    /**
     * A token signed by the provider whose payload was changed afterwards.
     */
    private function tamperedToken(): string
    {
        $now = $this->clock->now()->getTimestamp();
        [$head, , $signature] = explode('.', $this->op->sign(['iss' => FakeOpenIdProvider::ISSUER, 'sub' => 'x']));
        $payload = rtrim(strtr(base64_encode(json_encode([
            'iss' => FakeOpenIdProvider::ISSUER,
            'aud' => FakeOpenIdProvider::CLIENT_ID,
            'sub' => 'admin',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $head . '.' . $payload . '.' . $signature;
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'issuer' => FakeOpenIdProvider::ISSUER,
            'client_id' => FakeOpenIdProvider::CLIENT_ID,
            'client_secret' => FakeOpenIdProvider::CLIENT_SECRET,
            'redirect_uri' => FakeOpenIdProvider::REDIRECT_URI,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function driver(array $overrides = [], ?ClientInterface $http = null, ?RecordingSession $session = null): OidcDriver
    {
        return new OidcDriver(
            OidcOptions::fromArray(array_merge($this->settings(), $overrides)),
            $http ?? $this->op->client(),
            new Psr17Factory(),
            $session ?? $this->session,
            $this->cache,
            $this->clock,
        );
    }

    private function assertRejected(string $reason, Closure $attempt): void
    {
        try {
            $attempt();
        } catch (AuthenticationFailed $e) {
            self::assertStringContainsString($reason, $e->getMessage());

            return;
        }

        self::fail(sprintf('Expected AuthenticationFailed with "%s".', $reason));
    }
}
