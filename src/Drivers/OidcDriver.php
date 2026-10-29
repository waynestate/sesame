<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use DateTimeImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use League\OAuth2\Client\OptionProvider\PostAuthOptionProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Clock\SystemClock;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Drivers\Oidc\BasicAuthOptionProvider;
use Waynestate\Sesame\Drivers\Oidc\Discovery;
use Waynestate\Sesame\Drivers\Oidc\IdTokenValidator;
use Waynestate\Sesame\Drivers\Oidc\LeagueProvider;
use Waynestate\Sesame\Drivers\Oidc\OidcOptions;
use Waynestate\Sesame\Drivers\Oidc\PendingLogins;
use Waynestate\Sesame\Drivers\Oidc\ProcessCache;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\LoginStep;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\RemembersReturnTo;
use Waynestate\Sesame\ReturnTo;
use Waynestate\Sesame\SessionPort;

/**
 * The oidc driver: browser sign-in with OpenID Connect, Microsoft Entra ID
 * first.
 *
 * "Open Sesame" said to the right door, then checked at the counter. The
 * driver runs the authorization code flow with PKCE (S256):
 *
 *   1. A request without a code is the start. The driver makes a fresh
 *      state and nonce, has league/oauth2-client build the authorization
 *      URL and the PKCE verifier, keeps all three in the app's session
 *      (bound to this browser, single use, expiring), and answers with a
 *      Redirect to the identity provider.
 *   2. A request with a code (or an error) is the callback. The pending
 *      sign-in for its state is taken out of the session first, so a
 *      replayed callback finds nothing. league/oauth2-client exchanges the
 *      code with the PKCE verifier and the client secret, and SESAME's own
 *      validator (the same firebase/php-jwt and JwksProvider the oauth2
 *      driver uses) checks the ID token: signature on an RSA allowlist,
 *      iss, aud, azp, exp, nbf, iat and the nonce.
 *
 * The endpoints come from the issuer's discovery document, kept in a PSR-16
 * cache, unless config names them. No prompt and no max_age are sent by
 * default, so an existing Entra session signs the user in without a click.
 * A start request with step_up=1 adds the app's configured step-up
 * parameters (prompt=login by default, and optionally max_age, acr_values
 * and claims), and its callback then requires a fresh auth_time.
 *
 * The username comes from one configurable claim (accessid by default).
 * Falling back to the part of preferred_username before the "@" is an
 * opt-in (oidc.username_fallback), because users can change
 * preferred_username. iss, sub, tid and oid are passed to the resolver so
 * apps can key accounts on issuer plus sub, or tenant plus oid. The roles
 * claim (wsuRoles by default) becomes the "roles" attribute, the claims
 * the app lists in oidc.claims (wsuRoles and accessid by default) are
 * passed through under their own names, and auth_time
 * and amr become the identity's MFA facts with the same rules as the cas
 * driver's authenticationDate: the driver never invents a sign-in time.
 *
 * The access token and the ID token are never stored: the identity is all
 * the session keeps.
 */
final class OidcDriver implements Driver, RemembersReturnTo
{
    /**
     * The class whose presence means league/oauth2-client is installed.
     */
    public const LIBRARY_CLASS = GenericProvider::class;

    /**
     * The claims that become read-only identity attributes, as single values.
     *
     * @var list<string>
     */
    private const ATTRIBUTE_CLAIMS = ['iss', 'sub', 'oid', 'tid', 'email', 'name', 'preferred_username'];

    /**
     * The OAuth 2.0 error codes are ASCII words; anything else from the
     * provider is not echoed.
     */
    private const ERROR_CODE_PATTERN = '/^[a-z0-9_]{1,64}$/D';

    private const MAX_CODE_LENGTH = 4096;

    private const MAX_STATE_LENGTH = 512;

    private const MAX_RETURN_TO_LENGTH = 2048;

    /**
     * How old a step-up sign-in may be, in seconds, when the app configured
     * no oidc.step_up.max_age: a step-up is always bounded.
     */
    public const DEFAULT_STEP_UP_MAX_AGE = 300;

    private readonly ClockInterface $clock;

    private readonly CacheInterface $cache;

    private readonly Discovery $discovery;

    private readonly PendingLogins $pending;

    private readonly GuzzleClientInterface $tokenHttp;

    private ?string $returnTo = null;

    public function __construct(
        private readonly OidcOptions $options,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        SessionPort $session,
        ?CacheInterface $cache = null,
        ?ClockInterface $clock = null,
    ) {
        self::assertLibraryInstalled();

        $this->clock = $clock ?? new SystemClock();
        $this->cache = $cache ?? new ProcessCache($this->clock);
        $this->discovery = new Discovery(
            $options->issuer,
            $http,
            $requests,
            $this->cache,
            $options->cacheTtlSeconds,
            $options->endpoints,
        );
        $this->pending = new PendingLogins($session, $this->clock, $options->stateTtlSeconds);

        // league/oauth2-client sends the token request through Guzzle. When
        // the app's PSR-18 client is Guzzle, it is used for that too, so one
        // client (and one set of timeouts and TLS settings) serves every
        // call; LeagueProvider turns redirects off on each token request
        // whatever that client's own setting is. Otherwise a Guzzle client
        // is built that does not follow redirects and gives up after ten
        // seconds. Discovery and the key set go through PSR-18
        // sendRequest(), which never follows redirects in Guzzle, and both
        // treat any answer but 2xx (200 for discovery) as a failure.
        $this->tokenHttp = $http instanceof GuzzleClientInterface
            ? $http
            : new GuzzleClient(['allow_redirects' => false, 'connect_timeout' => 5, 'timeout' => 10]);
    }

    /**
     * Fail with a clear error when league/oauth2-client is missing.
     *
     * SESAME keeps protocol libraries optional: the oidc driver is the only
     * part that needs this one, so it sits in "suggest", and this check
     * turns a missing class into an instruction.
     *
     * @throws InvalidConfig When the library is not installed.
     */
    public static function assertLibraryInstalled(string $class = self::LIBRARY_CLASS): void
    {
        if (! class_exists($class)) {
            throw new InvalidConfig('The oidc driver needs league/oauth2-client. Install it with: composer require league/oauth2-client');
        }
    }

    public function name(): string
    {
        return 'oidc';
    }

    public function login(ServerRequestInterface $request): LoginStep
    {
        $query = $request->getQueryParams();
        $this->returnTo = null;

        if (array_key_exists('code', $query) || array_key_exists('error', $query)) {
            return $this->callback($query);
        }

        return $this->start($query);
    }

    /**
     * The provider's end-session URL, or null when the app has not asked
     * for a provider logout (no post_logout_redirect_uri), or the provider
     * publishes no end-session endpoint.
     *
     * The return address is the configured post_logout_redirect_uri, which
     * must also be registered on the app registration: the provider only
     * sends the user back to an address it knows. The $returnTo argument is
     * not used here, because the provider would refuse an address it has
     * not registered.
     */
    public function logoutUrl(string $returnTo): ?string
    {
        $postLogout = $this->options->postLogoutRedirectUri;

        if ($postLogout === null) {
            return null;
        }

        try {
            $endSession = $this->discovery->optionalEndpoint('end_session_endpoint');
        } catch (AuthenticationFailed) {
            // The local session is already gone; without the document the
            // user is simply sent to the local return-to.
            return null;
        }

        if ($endSession === null) {
            return null;
        }

        $query = http_build_query([
            'client_id' => $this->options->clientId,
            'post_logout_redirect_uri' => $postLogout,
        ], '', '&', PHP_QUERY_RFC3986);

        return $endSession . (str_contains($endSession, '?') ? '&' : '?') . $query;
    }

    /**
     * The redirect URI: the host every return-to must stay on.
     */
    public function serviceUrl(): string
    {
        return $this->options->redirectUri;
    }

    public function returnTo(): ?string
    {
        return $this->returnTo;
    }

    /**
     * What var_dump and print_r show: the options (with the secret
     * redacted) and nothing that holds a credential.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['options' => $this->options, 'returnTo' => $this->returnTo];
    }

    /**
     * Start a sign-in: remember the state, nonce and PKCE verifier, then
     * send the user to the identity provider.
     *
     * @param array<mixed> $query
     */
    private function start(array $query): Redirect
    {
        $stepUp = in_array($query['step_up'] ?? null, ['1', 'true'], true);
        $state = self::random();
        $nonce = self::random();

        $params = [
            'state' => $state,
            'nonce' => $nonce,
            'scope' => $this->options->scopes,
            'response_mode' => 'query',
        ];

        if ($stepUp) {
            $params += $this->stepUpParameters();
        }

        $provider = $this->provider($this->discovery->endpoint('authorization_endpoint'), '');
        $url = $provider->getAuthorizationUrl($params);
        $verifier = $provider->getPkceCode();

        if (! is_string($verifier) || strlen($verifier) < 43) {
            throw new AuthenticationFailed('The PKCE code verifier could not be made (oidc.pkce).');
        }

        $now = $this->clock->now()->getTimestamp();

        $this->pending->put($state, [
            'nonce' => $nonce,
            'verifier' => $verifier,
            'expires' => $this->pending->expiresAt(),
            'started' => $now,
            'return_to' => $this->safeReturnTo($query['return_to'] ?? null),
            'step_up' => $stepUp,
            'max_age' => $stepUp ? $this->options->stepUpMaxAge : null,
        ]);

        return new Redirect($url);
    }

    /**
     * Finish a sign-in: check the state, exchange the code, validate the ID
     * token, and build the identity.
     *
     * @param array<mixed> $query
     */
    private function callback(array $query): Authenticated
    {
        $state = $query['state'] ?? null;

        if (! is_string($state) || $state === '' || strlen($state) > self::MAX_STATE_LENGTH) {
            throw new AuthenticationFailed('The callback has no usable state (oidc.state).');
        }

        // Taken out before anything else, so the state is spent even when
        // the rest of the callback fails.
        $pending = $this->pending->take($state);

        if ($pending === null) {
            throw new AuthenticationFailed('The callback state is unknown, used or expired (oidc.state).');
        }

        if (array_key_exists('error', $query)) {
            $error = $query['error'];
            $code = is_string($error) && preg_match(self::ERROR_CODE_PATTERN, $error) === 1 ? $error : 'unknown';

            throw new AuthenticationFailed(sprintf('The identity provider refused the sign-in (oidc.provider_error: %s).', $code));
        }

        $code = $query['code'] ?? null;

        if (! is_string($code) || $code === '' || strlen($code) > self::MAX_CODE_LENGTH
            || preg_match('/^[\x21-\x7E]+$/D', $code) !== 1) {
            throw new AuthenticationFailed('The callback has no usable authorization code (oidc.code).');
        }

        $idToken = $this->exchange($code, $pending['verifier']);

        $validator = new IdTokenValidator(
            new JwksProvider(
                $this->http,
                $this->requests,
                $this->cache,
                $this->clock,
                $this->discovery->endpoint('jwks_uri'),
                $this->options->cacheTtlSeconds,
                'oidc',
            ),
            $this->clock,
            $this->options->issuer,
            $this->options->clientId,
            $this->options->algorithms,
            $this->options->leewaySeconds,
        );

        $claims = $validator->validate($idToken, $pending['nonce']);

        if ($pending['step_up']) {
            $this->assertFreshSignIn($claims, $pending['started'], $pending['max_age']);
        }

        $identity = $this->identity($claims);
        $this->returnTo = $pending['return_to'];

        return new Authenticated($identity);
    }

    /**
     * Exchange the code for tokens and return the ID token.
     *
     * Every failure becomes one AuthenticationFailed with no previous
     * exception: the HTTP client's exceptions hold the request, and the
     * request holds the client secret, so none of them is chained. Only an
     * OAuth 2.0 error code of the expected shape is kept, for the logs.
     */
    private function exchange(string $code, string $verifier): string
    {
        $provider = $this->provider('', $this->discovery->endpoint('token_endpoint'));
        $provider->setPkceCode($verifier);

        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        } catch (IdentityProviderException $e) {
            $error = $e->getMessage();
            $reason = preg_match(self::ERROR_CODE_PATTERN, $error) === 1 ? $error : 'error';

            throw new AuthenticationFailed(sprintf('The token endpoint refused the code (oidc.token_exchange: %s).', $reason));
        } catch (AuthenticationFailed $e) {
            // Raised by SESAME itself (a redirect answer), with a safe message.
            throw new AuthenticationFailed($e->getMessage());
        } catch (\Throwable) {
            throw new AuthenticationFailed('The token endpoint could not be used (oidc.token_exchange).');
        }

        $values = $token instanceof AccessToken ? $token->getValues() : [];
        $idToken = $values['id_token'] ?? null;

        if (! is_string($idToken) || $idToken === '') {
            throw new AuthenticationFailed('The token response has no ID token (oidc.missing_id_token).');
        }

        return $idToken;
    }

    /**
     * A league/oauth2-client provider for one step. The secret goes into
     * the provider only; the provider lives for one call and is never kept.
     */
    private function provider(string $authorizationEndpoint, string $tokenEndpoint): LeagueProvider
    {
        $basic = $this->options->clientAuth === 'client_secret_basic';

        return new LeagueProvider([
            'clientId' => $this->options->clientId,
            'clientSecret' => $this->options->clientSecret(),
            'redirectUri' => $this->options->redirectUri,
            'urlAuthorize' => $authorizationEndpoint,
            'urlAccessToken' => $tokenEndpoint,
            'urlResourceOwnerDetails' => '',
            'scopes' => $this->options->scopes,
            'scopeSeparator' => ' ',
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
        ], [
            'httpClient' => $this->tokenHttp,
            'optionProvider' => $basic ? new BasicAuthOptionProvider() : new PostAuthOptionProvider(),
        ]);
    }

    /**
     * The step-up parameters the app configured. Only the app's config
     * feeds these, never the request, so a user can ask for a step-up but
     * cannot choose what it is.
     *
     * @return array<string, string>
     */
    private function stepUpParameters(): array
    {
        $params = [];

        if ($this->options->stepUpPrompt !== null) {
            $params['prompt'] = $this->options->stepUpPrompt;
        }

        if ($this->options->stepUpMaxAge !== null) {
            $params['max_age'] = (string) $this->options->stepUpMaxAge;
        }

        if ($this->options->stepUpAcrValues !== null) {
            $params['acr_values'] = $this->options->stepUpAcrValues;
        }

        if ($this->options->stepUpClaims !== null) {
            $params['claims'] = $this->options->stepUpClaims;
        }

        return $params;
    }

    /**
     * A step-up sign-in must be fresh: auth_time is required, it must not
     * be in the future (beyond the leeway), it must not be before the
     * sign-in started when prompt=login asked for new credentials, and it
     * must be within max_age: the configured one (sent to the provider;
     * Core 3.1.2.1 makes auth_time required then), or
     * DEFAULT_STEP_UP_MAX_AGE when none is configured, so a step-up is never
     * unbounded.
     *
     * @param array<string, mixed> $claims
     */
    private function assertFreshSignIn(array $claims, int $started, ?int $maxAge): void
    {
        $authTime = $claims['auth_time'] ?? null;

        if (! is_int($authTime)) {
            throw new AuthenticationFailed('The step-up sign-in has no "auth_time" claim (oidc.step_up).');
        }

        $leeway = $this->options->leewaySeconds;
        $now = $this->clock->now()->getTimestamp();

        if ($authTime > $now + $leeway) {
            throw new AuthenticationFailed('The step-up sign-in has an auth_time in the future (oidc.step_up).');
        }

        if ($this->options->stepUpPrompt === 'login' && $authTime < $started - $leeway) {
            throw new AuthenticationFailed('The step-up sign-in reused an earlier sign-in (oidc.step_up).');
        }

        $maxAge ??= self::DEFAULT_STEP_UP_MAX_AGE;

        if ($now - $authTime > $maxAge + $leeway) {
            throw new AuthenticationFailed('The step-up sign-in is older than max_age (oidc.step_up).');
        }
    }

    /**
     * The identity, mapped from the verified claims.
     *
     * @param array<string, mixed> $claims
     */
    private function identity(array $claims): Identity
    {
        $authTime = self::authTime($claims['auth_time'] ?? null);
        $now = $this->clock->now();

        // The sign-in time is the provider's auth_time, unless it is missing
        // or more than five minutes ahead of the clock.
        $authenticatedAt = $authTime !== null && $authTime <= $now->modify('+5 minutes') ? $authTime : $now;

        return new Identity(
            $this->username($claims),
            $this->attributes($claims),
            $this->name(),
            $authenticatedAt,
            $authTime,
            $this->amr($claims['amr'] ?? null),
            self::authContexts($claims['acrs'] ?? null),
        );
    }

    /**
     * The username: the configured claim, or, when it is missing and the app
     * opted in to the fallback, the part of preferred_username before the
     * "@". The fallback is off by default: preferred_username is a value the
     * user (or an admin) can change, so it is no stable account key.
     *
     * @param array<string, mixed> $claims
     */
    private function username(array $claims): string
    {
        $value = self::claim($claims, $this->options->usernameClaim);

        if ((! is_string($value) || trim($value) === '') && $this->options->usernameFallback) {
            $preferred = $claims['preferred_username'] ?? null;

            if (is_string($preferred) && str_contains($preferred, '@')) {
                $value = strstr($preferred, '@', true);
            }
        }

        if (! is_string($value)) {
            $value = '';
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 256 || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new AuthenticationFailed(sprintf(
                'The ID token has no usable "%s" claim (oidc.missing_claim). Map the claim on the app registration, set SESAME_OIDC_USERNAME_CLAIM (oidc.username_claim) to a claim the token carries, or opt in to SESAME_OIDC_USERNAME_FALLBACK (oidc.username_fallback), which uses the user-changeable preferred_username.',
                $this->options->usernameClaim,
            ));
        }

        return $value;
    }

    /**
     * The read-only attributes: the single-valued claims, and the roles.
     *
     * @param array<string, mixed> $claims
     * @return array<string, list<string>>
     */
    private function attributes(array $claims): array
    {
        $attributes = [];

        foreach (self::ATTRIBUTE_CLAIMS as $name) {
            $value = $claims[$name] ?? null;

            if (is_string($value) && $value !== '') {
                $attributes[$name] = [$value];
            }
        }

        $roles = self::stringList(self::claim($claims, $this->options->rolesClaim));

        if ($roles !== []) {
            $attributes['roles'] = $roles;
        }

        // The app's chosen claims, under the names the app asked for (a
        // namespaced claim is found by its short name). The fixed
        // attributes above are never overwritten.
        foreach ($this->options->claims as $name) {
            $values = self::stringList(self::claim($claims, $name));

            if ($values !== [] && ! isset($attributes[$name])) {
                $attributes[$name] = $values;
            }
        }

        return $attributes;
    }

    /**
     * The amr list, plus "mfa" when assume_mfa is on.
     *
     * @return list<string>
     */
    private function amr(mixed $value): array
    {
        $amr = self::stringList($value);

        if ($this->options->assumeMfa && ! in_array('mfa', $amr, true)) {
            $amr[] = 'mfa';
        }

        return $amr;
    }

    /**
     * One claim by name. A claim mapped through a custom-claim namespace
     * may arrive under a longer name (for example
     * "http://schemas.example.test/claims/wsuRoles" or "extn.wsuRoles"):
     * the candidates are the claim with the exact name and every claim
     * whose name ends in "/<name>" or ".<name>", counted together. Exactly
     * one candidate is used; two or more (for example an exact "wsuRoles"
     * beside a namespaced one) are ambiguous, and none is used, so a second
     * claim can never quietly decide which roles count.
     *
     * @param array<string, mixed> $claims
     */
    private static function claim(array $claims, string $name): mixed
    {
        $matches = [];

        foreach ($claims as $key => $value) {
            $key = (string) $key;

            if ($key === $name || str_ends_with($key, '/' . $name) || str_ends_with($key, '.' . $name)) {
                $matches[] = $value;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * A claim as a list of non-empty strings: a string becomes a one-item
     * list, a list keeps its string items, and anything else is dropped.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * The auth_time claim as a time, or null when it is missing or is not a
     * non-negative integer.
     */
    private static function authTime(mixed $value): ?DateTimeImmutable
    {
        if (! is_int($value) || $value < 0) {
            return null;
        }

        return new DateTimeImmutable('@' . $value);
    }

    /**
     * The authentication contexts from the acrs claim, c1 to c99 only, as in
     * the gateway driver.
     *
     * @return list<string>
     */
    private static function authContexts(mixed $value): array
    {
        $contexts = [];

        foreach (self::stringList($value) as $item) {
            if (preg_match('/^c[1-9][0-9]?$/D', $item) === 1) {
                $contexts[] = $item;
            }
        }

        return $contexts;
    }

    /**
     * The return-to for after the sign-in, or null when none was asked for
     * or it fails the ReturnTo rule (an absolute https URL on the redirect
     * URI's host).
     */
    private function safeReturnTo(mixed $returnTo): ?string
    {
        if (! is_string($returnTo) || $returnTo === '' || strlen($returnTo) > self::MAX_RETURN_TO_LENGTH
            || preg_match('/[\x00-\x20\x7F\\\\]/', $returnTo) === 1) {
            return null;
        }

        return ReturnTo::safe($returnTo, $this->options->redirectUri) === $returnTo ? $returnTo : null;
    }

    /**
     * 32 random bytes, base64url-encoded: the state and the nonce.
     */
    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
