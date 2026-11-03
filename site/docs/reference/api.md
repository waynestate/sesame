---
title: "API | Sesame docs"
description: "The classes and global functions an app uses: Config, DriverFactory, Sesame, the session, the resolver, and the sesame_* functions."
url: https://sesame.apps.wayne.edu/docs/reference/api.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# API[#](https://sesame.apps.wayne.edu/docs/reference/api.html#api)

The public surface of waynestate/sesame, named exactly as it is in the code under `src/`: the facade, the factory, the value objects, the ports an app implements, and the global functions the raw-PHP path calls. The incantation is a constructor.

## Classes[#](https://sesame.apps.wayne.edu/docs/reference/api.html#classes)

Unless noted, everything below is in the `Waynestate\Sesame` namespace.

### Config[#](https://sesame.apps.wayne.edu/docs/reference/api.html#config)

The library's config, read from a plain array. `final readonly class Config`.

```php
public static function fromArray(#[\SensitiveParameter] array $config): self
```

Every constructor parameter is a public readonly property, in this order: `driver`, `environment`, `fakeUsername`, `fakeAttributes`, `casBaseUrl`, `casServiceUrl`, `casProtocol`, `casRenew`, `casAssumeMfa`, `sessionIdleMinutes`, `sessionAbsoluteMinutes`, `gatewayHeader`, `gatewayAlgorithm`, `gatewayPublicKey`, `gatewayIssuer`, `gatewayAudience`, `gatewayLeewaySeconds`, `gatewayTrustedProxies`, `gatewayMaxLifetimeSeconds`, `gatewayAllowWithoutReplayGuard`, `oauth2Issuer`, `oauth2JwksUrl`, `oauth2Audience`, `oauth2LeewaySeconds`, `oauth2RequiredScopes`, `oauth2RequiredRoles`, `oauth2IdentityClaim`, `oauth2JwksCacheTtlSeconds`, `oauth2RequireAtJwt`, `oidc` (an `?OidcOptions`) and `gatewayPublicKeys`. Keys, defaults and bounds: see [Configuration](https://sesame.apps.wayne.edu/docs/reference/configuration.html).

### DriverFactory[#](https://sesame.apps.wayne.edu/docs/reference/api.html#driverfactory)

Build a driver from config. `final class DriverFactory`.

```php
public static function make(
    Config $config,
    ?ClientInterface $http = null,
    ?RequestFactoryInterface $requests = null,
    ?ClockInterface $clock = null,
    ?ReplayGuard $replayGuard = null,
    ?CacheInterface $jwksCache = null,
    ?SessionPort $session = null,
): Driver
```

What each argument is for:

- `$http` (PSR-18) and `$requests` (PSR-17): the cas driver talks to a CAS server over HTTP, and the factory cannot build an HTTP client itself, so the app passes both.
- `$clock` (PSR-20): optional, for the cas, gateway and oauth2 drivers' sign-in time; without one they use the system clock.
- `$replayGuard`: the gateway driver needs one (for example a `Psr16ReplayGuard` over a PSR-16 cache), so a captured assertion cannot be replayed. Without one, the gateway driver refuses to start, unless the config says `gateway.allow_without_replay_guard` (local development only).
- `$jwksCache` (PSR-16): the oauth2 driver keeps the fetched key set in it; without one, the oauth2 driver refuses to start. The oidc driver uses it too when passed (discovery document and key set), otherwise it keeps both in process memory.
- `$session`: the oidc driver signs the user in through the browser, so it keeps its state, nonce and PKCE verifier in the app's session.

A missing dependency is an `InvalidConfig` error that names what to pass, and an unknown `driver` value is one too. The oidc driver also needs league/oauth2-client, which SESAME suggests but does not require. The driver classes themselves live in `Waynestate\Sesame\Drivers`; see [Drivers](https://sesame.apps.wayne.edu/docs/reference/drivers.html).

### Sesame[#](https://sesame.apps.wayne.edu/docs/reference/api.html#sesame)

The facade an app talks to. `final class Sesame`. It holds a driver (or a factory for one, built on first use, so checking a session never pays for the driver's construction), the app's user resolver, the app's session and the config, and it runs the one rule the code holds: every driver ends in `findOrImportUser(Identity)`, and the app owns the session. Time is read through a PSR-20 clock, so the session timeouts are testable.

```php
public function __construct(
    Driver|Closure $driver,
    private readonly UserResolver $resolver,
    private readonly SessionPort $session,
    private readonly Config $config,
    ?ClockInterface $clock = null,
    private readonly ?LoggerInterface $logger = null,
)
```

```php
public function login(ServerRequestInterface $request): mixed
public function returnTo(): ?string
public function serviceUrl(): ?string
public function user(): mixed
public function requireMfa(int $maxAgeSeconds = 28800): MfaResult
public function hasAuthContext(string $id): bool
public function logout(string $returnTo): ?string
public function signOutLocal(): void
public const LOGOUT_TOKEN_FIELD = 'sesame_logout_token'
public const LOGOUT_TOKEN_KEY = 'sesame.logout_token'
public function logoutToken(): string
public function isLogoutToken(#[\SensitiveParameter] mixed $token): bool
public function safeReturnTo(string $returnTo): string
```

- `login()`: returns the `Redirect` unchanged when the user must go to the identity provider. Otherwise it resolves the user: when the resolver returns a user it regenerates the session ID before it stores anything, and returns the user. What it stores is the user, a trimmed identity (username, driver, time, the MFA facts and the authentication contexts, but not the raw IdP attributes, so the session stays small and plain), and the timestamps that start the session's clocks. When the resolver returns null the app has refused the identity: the session is left untouched, nothing is stored, and null is returned, so a refused identity never ends up signed in. An `AuthenticationFailed` from the driver is rethrown unchanged.
- `returnTo()`: where to send the user after the sign-in that just completed, or null when the driver remembers none. Only drivers that implement `RemembersReturnTo` (the oidc driver) answer, and the value has already passed the `ReturnTo` rule.
- `serviceUrl()`: the driver's configured service URL, or null when the driver has none.
- `user()`: the app's user for the current session, or null when there is none. The session is checked against the timeouts before it is trusted, and a stale session is signed out on the spot, so a stale session can never pass one check and fail another. When the resolver implements `RefreshesUser`, the stored user is looked up again before the answer, and a null result signs the session out, so a deleted account stops reading as signed in from the next request on.
- `requireMfa()`: decides whether the signed-in user's MFA is still good. The session is checked first, through `user()`, so a stale session is signed out before the MFA question is even asked; with no user, or no identity to read, the answer is `NO_AUTH_TIME`: the check fails closed.
- `hasAuthContext()`: decides whether the signed-in identity has the given authentication context, with the same shape and the same fails-closed answer (`false`).
- `logout()`: logs the user out of the app, then points at the identity provider. The app session is invalidated before the provider's URL is built, so a failed redirect can never leave a live session behind; the pointer goes out even when the session holds no user of its own, because the single sign-on session at the provider may still be alive there. Returns the provider's logout URL, or null when the driver has none.
- `signOutLocal()`: clears SESAME's session keys and invalidates the session, without pointing at the identity provider. The guard's logout uses it: the provider's single sign-on session is left alone.
- `logoutToken()`: the logout CSRF token for this session, made on first use (64 hex characters). The raw-PHP logout (`sesame_logout()`) only runs for a POST that carries this token, so another site cannot sign the user out with a link or a hidden form. Laravel apps use Laravel's own CSRF token instead (the logout route is in the web middleware group).
- `isLogoutToken()`: whether the given token is this session's logout CSRF token. A session that never made one accepts nothing.
- `safeReturnTo()`: the safe URL to send the user to after logout. The return-to must be an absolute https URL on the service URL's scheme, host and port; anything else sends the user to the service URL instead, so a logout can never be turned into an open redirect.
- `LOGOUT_TOKEN_FIELD`: the form field the raw-PHP logout reads its CSRF token from. `LOGOUT_TOKEN_KEY`: the session key that holds the logout CSRF token.

When a logger is set, sign-in and sign-out events are logged through it: a broken logger never changes what the facade returns or throws, and no ticket, session ID, token, assertion, cookie or header ever reaches the log.

### Identity[#](https://sesame.apps.wayne.edu/docs/reference/api.html#identity)

The verified identity a driver hands to the resolver. `final readonly class Identity`; the constructor refuses an empty username with `InvalidArgumentException`.

```php
public function __construct(
    public string $username,
    public array $attributes,
    public string $driver,
    public DateTimeImmutable $authenticatedAt,
    public ?DateTimeImmutable $authTime = null,
    public array $amr = [],
    private array $authContexts = [],
)
```

- `attribute(string $name): ?string`: the first value of one attribute, or null when the attribute is absent.
- `isFromNewLogin(): ?bool`: read from the CAS `isFromNewLogin` attribute: true for the string "true" (any case), false for "false", and null when the attribute is absent or carries any other value. Drivers that do not release the attribute answer null; the identity never invents one.
- `usedLongTermToken(): ?bool`: the same shape, read from the CAS `longTermAuthenticationRequestTokenUsed` attribute.
- `authContexts(): array`: the authentication contexts verified for this sign-in, in the order the driver reported them. Empty when no step-up was required.
- `hasAuthContext(string $id): bool`: whether this sign-in verified the given authentication context: a membership test on the list, never an equality on the whole list.

### LoginStep, Authenticated, Redirect[#](https://sesame.apps.wayne.edu/docs/reference/api.html#loginstep-authenticated-redirect)

A login ends in one of two shapes, both `final` and both extending the `abstract class LoginStep`, which is empty on purpose:

```php
final class Authenticated extends LoginStep
{
    public function __construct(
        public readonly Identity $identity,
    ) {}
}

final class Redirect extends LoginStep
{
    public function __construct(
        public readonly string $url,
    ) {}
}
```

`Authenticated`: the identity is verified; this is who the user is. `Redirect`: send the user to this URL to continue the login.

### Driver[#](https://sesame.apps.wayne.edu/docs/reference/api.html#driver)

One way to verify an identity. A driver knows one protocol and nothing about the app: it turns a request into a `LoginStep`, and the core does the rest. Switching driver is a config change with no code change.

```php
interface Driver
{
    public function name(): string;
    public function login(ServerRequestInterface $request): LoginStep;
    public function logoutUrl(string $returnTo): ?string;
    public function serviceUrl(): ?string;
}
```

`logoutUrl()` is the identity provider's logout URL, or null when the driver has none; `serviceUrl()` is the configured service URL, or null, and it is the host a logout return-to must stay on.

### UserResolver, RefreshesUser, RemembersReturnTo[#](https://sesame.apps.wayne.edu/docs/reference/api.html#userresolver-refreshesuser-remembersreturnto)

The port the app implements, and the optional extras a resolver or driver can use:

```php
interface UserResolver
{
    public function findOrImportUser(Identity $identity): mixed;
}

interface RefreshesUser
{
    public function refreshUser(mixed $user): mixed;
}

interface RemembersReturnTo
{
    public function returnTo(): ?string;
}
```

`UserResolver` is the one place every driver ends: turn a verified identity into the app's user, or return null to refuse it. `RefreshesUser` lets the app look the stored user up again on every `user()` call. `RemembersReturnTo` lets a driver remember where to send the user after the sign-in that just completed.

### Session[#](https://sesame.apps.wayne.edu/docs/reference/api.html#session)

The app's session, seen through a port:

```php
interface SessionPort
{
    public function regenerate(): void;
    public function get(string $key): mixed;
    public function put(string $key, mixed $value): void;
    public function forget(string $key): void;
    public function invalidate(): void;
}
```

`Waynestate\Sesame\Session\NativeSession` is the port over the native PHP session. It never starts a session and never sets cookie flags: starting the session is the app's job (`session_start()`), and hardening the session cookie is HELMET's job. It reads and writes `$_SESSION` on a session that is already active, and throws `LogicException` when it is not. `invalidate()` is the one operation that retires the session: it clears the data, expires the cookie on the client, and destroys the session, so a stolen ID is dead after logout.

### MFA[#](https://sesame.apps.wayne.edu/docs/reference/api.html#mfa)

The one rule that decides whether an identity's MFA is still good. `final class Mfa`:

```php
public const MFA_METHODS = ['mfa', 'otp', 'hwk', 'swk', 'fido', 'sms', 'pop'];
public const FUTURE_SKEW_SECONDS = 60;
public static function satisfied(Identity $identity, int $maxAgeSeconds, DateTimeImmutable $now): MfaResult
```

An identity satisfies MFA only when the identity provider reported a sign-in time (`auth_time`), that time is recent enough, and the sign-in used an MFA method (`amr`). Anything the provider did not report is treated as missing, so the check fails closed: a driver that carries no MFA facts is always denied. The checks run in a fixed order, so the reason is stable:

1. no `auth_time`: `NO_AUTH_TIME`
2. `auth_time` more than 60 seconds in the future: `FUTURE_AUTH_TIME`
3. `auth_time` older than `$maxAgeSeconds`: `TOO_OLD`
4. no MFA method in `amr`: `NO_MFA_METHOD`
5. otherwise: `SATISFIED`

A sign-in exactly `$maxAgeSeconds` old is still satisfied.

`final readonly class MfaResult` carries the answer:

```php
public const SATISFIED = 'SATISFIED';
public const NO_AUTH_TIME = 'NO_AUTH_TIME';
public const NO_MFA_METHOD = 'NO_MFA_METHOD';
public const TOO_OLD = 'TOO_OLD';
public const FUTURE_AUTH_TIME = 'FUTURE_AUTH_TIME';
public function __construct(
    public bool $satisfied,
    public string $reason,
)
public function message(): string
```

`message()` is the human form of the result, for a message or a log line.

### ReturnTo[#](https://sesame.apps.wayne.edu/docs/reference/api.html#returnto)

The one rule for a return-to URL (after login or logout). `final class ReturnTo`:

```php
public static function safe(string $returnTo, ?string $serviceUrl): string
```

A return-to must be an absolute URL on the same origin as the service URL: the same scheme, the same host and the same effective port (443 for https, 80 for http, when none is written), with no user name or password in it. The scheme must be https; plain http is allowed only for a localhost or 127.0.0.1 service URL, for local development. Anything else sends the user to the service URL instead, so a login or logout can never be turned into an open redirect, not even to another port on the same host. When the driver has no service URL there is nothing to check against, so the return-to is used as-is.

### OidcOptions[#](https://sesame.apps.wayne.edu/docs/reference/api.html#oidcoptions)

`Waynestate\Sesame\Drivers\Oidc\OidcOptions`: the `oidc` config block, validated. `final readonly class OidcOptions`.

```php
public const ALLOWED_ALGORITHMS = ['RS256', 'RS384', 'RS512'];
public const CLIENT_AUTH_METHODS = ['client_secret_post', 'client_secret_basic'];
public const MAX_CACHE_TTL_SECONDS = 3600;
public static function fromArray(#[\SensitiveParameter] array $oidc): self
public function clientSecret(): string
```

The keys, their environment variables and their defaults are in the oidc table on the [Configuration page](https://sesame.apps.wayne.edu/docs/reference/configuration.html).

### Setting[#](https://sesame.apps.wayne.edu/docs/reference/api.html#setting)

`Waynestate\Sesame\Support\Setting`: the one lookup for the library's shared settings. The real environment wins (getenv, then $\_ENV, then $\_SERVER); when it has nothing, the private waynestate/playbook package supplies Wayne State's shared values when it is installed. An empty value counts as missing, so a blank variable never shadows the playbook.

```php
public const ENVIRONMENT_ONLY = ['SESAME_OIDC_CLIENT_SECRET'];
public static function get(string $key): ?string
public static function env(string $key): ?string
```

`get()` answers from the environment, then the playbook, else null. `env()` answers from the environment only, and the playbook is never consulted. A key in `ENVIRONMENT_ONLY` never falls back to the playbook, so a shared package can never supply an app's secret.

## Functions[#](https://sesame.apps.wayne.edu/docs/reference/api.html#functions)

Plain functions in the global namespace behind a `sesame_` prefix, the same shape as WayneUI's standalone path, because that is what a framework-free application can consume. Each one is guarded with `function_exists`, so two copies of this file can never collide. The app owns the session: start it with `session_start()` before calling anything here, and let HELMET set the cookie flags.

```php
function sesame(?Sesame $instance = null): Sesame
function sesame_request(): ServerRequestInterface
function sesame_login(): mixed
function sesame_user(): mixed
function sesame_require_mfa(int $maxAgeSeconds = 28800): mixed
function sesame_require_context(string $id): mixed
function sesame_logout_token(): string
function sesame_logout(string $returnTo): void
```

- `sesame()`: hold the one `Sesame` instance for this process. Set it once at boot, read it after: `sesame($sesame);` then `sesame();`. Reading it with none set throws `LogicException`.
- `sesame_request()`: build the PSR-7 request for the current request from the globals. The PSR-17 factories come from nyholm/psr7, which the library already requires, so the raw-PHP path needs nothing but the autoloader.
- `sesame_login()`: run a login against the current request. When the driver answers with a `Redirect`, sends the 302 and stops the script, so no protected code runs after the redirect. A request that already has a user and no ticket returns that user without calling the driver, so a page that calls it on every request cannot bounce the signed-in user back to the identity provider in a loop. When a ticket completes the login, the user is sent to the driver's service URL (or the checked return-to the oidc sign-in began with) and the script stops, so the ticket never stays in the address bar. When the resolver refuses the identity, null is returned and no redirect is sent: the ticket is single-use, so leaving it in that one refused URL is harmless. The oidc driver's callback (a request with `state` and a `code` or an `error`) counts as a ticket here, and a request with `step_up=1` always goes to the driver too, so a signed-in user can be asked for a fresh sign-in.
- `sesame_user()`: the app's user for the current session, or null when there is none.
- `sesame_require_mfa()`: require that the signed-in user's MFA is still good. When it is satisfied, the app's user is returned, so a protected page can read the user and the MFA proof in one call; when it is not, `MfaNotSatisfied` is thrown, carrying the result with the stable reason and the human message.
- `sesame_require_context()`: require that the signed-in identity has the given authentication context. When it does, the app's user is returned; when it does not, `AuthContextNotSatisfied` is thrown, carrying the context id.
- `sesame_logout_token()`: the logout CSRF token for this session, for the logout form. The token is hex, so it needs no escaping in HTML:

```php
<form method="post" action="/logout.php">
  <input type="hidden" name="sesame_logout_token" value="<?= sesame_logout_token() ?>">
  <button>Sign out</button>
</form>
```

- `sesame_logout()`: log the user out, then send them on. Invalidates the app session, redirects to the identity provider's logout URL when the driver has one, otherwise to the safe return-to, and stops the script, so no protected code runs after the redirect. CSRF guard: the logout runs only for a POST whose body carries the session's token in the `sesame_logout_token` field. A GET answers 405 with `Allow: POST`, and a POST without the right token answers 403; both leave the session alone and stop the script, so another site cannot sign the user out with an image, a link or a forged form. The response carries `Cache-Control: no-store` and `Clear-Site-Data: "cache", "storage"`; `cookies` is deliberately left out, so the user is not signed out of every other app on the host.

## Exceptions[#](https://sesame.apps.wayne.edu/docs/reference/api.html#exceptions)

The exceptions an app meets, in `Waynestate\Sesame\Exception`:

- `AuthenticationFailed`: a driver could not verify the identity. The message can carry the ticket or other provider details, so it is never logged and should not be shown: log it for operators and show the user one generic line.
- `MfaNotSatisfied`: the MFA check is not satisfied; carries the `MfaResult` with the stable reason and the human message.
- `AuthContextNotSatisfied`: the identity lacks the authentication context; carries the context id.
- `InvalidConfig`: a config error at boot: a missing or invalid key from `Config::fromArray()`, or a driver missing the dependency `DriverFactory::make()` needs.
- `FakeDriverInProduction`: the `fake` driver refuses to boot in production.

## Where the rest lives[#](https://sesame.apps.wayne.edu/docs/reference/api.html#where-the-rest-lives)

- The driver implementations: `Waynestate\Sesame\Drivers` (`CasDriver`, `FakeDriver`, `GatewayDriver`, `OAuth2Driver`, `OidcDriver`, `ReplayGuard`, `Psr16ReplayGuard`), documented on the [Drivers](https://sesame.apps.wayne.edu/docs/reference/drivers.html) page.
- The Laravel adapter: `Waynestate\Sesame\Laravel` (`SesameServiceProvider`, `SesameGuard`, `Authenticate`, `RequireMfa`, `RequireAuthContext`, `LaravelSession`, `LaravelPsr16Cache`), documented in the [Laravel guide](https://sesame.apps.wayne.edu/docs/guides/laravel.html).
- The wayne-cas compatibility layer: `Waynestate\Sesame\WayneCas`, documented in [Moving from wayne-cas](https://sesame.apps.wayne.edu/docs/guides/moving-from-wayne-cas.html).

## Related[#](https://sesame.apps.wayne.edu/docs/reference/api.html#related)

- [Drivers](https://sesame.apps.wayne.edu/docs/reference/drivers.html)
- [Configuration](https://sesame.apps.wayne.edu/docs/reference/configuration.html)
- [Proof](https://sesame.apps.wayne.edu/docs/reference/proof.html)
- [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html)
- [Laravel](https://sesame.apps.wayne.edu/docs/guides/laravel.html)
