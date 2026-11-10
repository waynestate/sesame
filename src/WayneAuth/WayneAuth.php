<?php

declare(strict_types=1);

namespace Waynestate\Sesame\WayneAuth;

use GuzzleHttp\Client as GuzzleClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Exception\ProviderUnavailable;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Metrics\LoginMetrics;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\Support\JsonGuest;
use Waynestate\Sesame\UserResolver;

/**
 * The Wayne Auth bridge: the old `WayneAuth` class's public API, backed by
 * SESAME's sign-in (CAS at WSU).
 *
 * Legacy apps checked the old SSO cookie with three calls:
 *
 *     $wayne_auth = new WayneAuth($config['wayneauth']);
 *     if ($wayne_auth->authenticateSSO() == true) {
 *         $accessid = $wayne_auth->getAccessID();
 *     }
 *
 * Those calls keep working, unchanged, once the app swaps the line that
 * loaded the old class for its SESAME config file, which calls
 * WayneAuth::boot(). Nothing talks to the Wayne Auth service any more: no
 * cookie, no socket, no SSO host. The old config keys (`sso_host`,
 * `sso_port`, `ldap_host` and the rest) are accepted and ignored.
 *
 * What changes under the same names:
 *
 * - A guest is sent to the CAS sign-in by authenticateSSO() itself, and the
 *   script stops, so the app's "else redirect to the login page" branch no
 *   longer runs. Boot with `on_guest => deny` to keep the old answer (false)
 *   instead.
 * - A returning CAS ticket is validated, the user is stored in the app's
 *   session, and the browser is sent on to the service URL without the
 *   ticket. A ticket that does not validate throws AuthenticationFailed, as
 *   everywhere in SESAME, instead of answering false: answering false would
 *   send the browser back to CAS and round again, forever. A CAS server
 *   that cannot be reached throws ProviderUnavailable, its subclass.
 * - A guest's script request (XHR or fetch asking for JSON) gets a 401 with
 *   `{"signedIn":false}`, not the redirect to CAS.
 * - `_parseSSOCookie('d')`, which handed the app the user's password, has no
 *   equivalent: CAS never gives an app the password. It throws.
 * - The AccessID is the CAS username. Boot with `accessid_case` (`upper` or
 *   `lower`) when the app compares it against a list in one case.
 *
 * Open sesame, the polite way: the old door keeps its handle, but it opens
 * onto the new hall.
 *
 * @deprecated A bridge, deprecated on arrival: it exists so moving off Wayne
 *             Auth is one line instead of a project. Move to SESAME's native
 *             API next: sesame_login() where the app called
 *             authenticateSSO(), and the username of the Identity your
 *             UserResolver receives (or sesame()->sessionStatus()['username'])
 *             where it called getAccessID(). See "Moving off Wayne Auth" in
 *             the docs.
 */
class WayneAuth
{
    /** Send a guest to the CAS sign-in and stop the script (the default). */
    public const ON_GUEST_REDIRECT = 'redirect';

    /** Answer false for a guest, the way Wayne Auth did, and redirect nowhere. */
    public const ON_GUEST_DENY = 'deny';

    /** @var list<string> */
    private const ON_GUEST = [self::ON_GUEST_REDIRECT, self::ON_GUEST_DENY];

    /** @var list<string> */
    private const ACCESSID_CASES = ['asis', 'upper', 'lower'];

    /**
     * The options boot() set for every bridge the app creates after it.
     *
     * @var array{on_guest: string, accessid_case: string}
     */
    private static array $defaults = ['on_guest' => self::ON_GUEST_REDIRECT, 'accessid_case' => 'asis'];

    private static bool $deprecationSaid = false;

    /**
     * The signed-in user's AccessID after authenticateSSO(), or null. Public
     * because the old class kept it in a public property, and some apps read
     * it directly.
     */
    public ?string $accessid = null;

    /**
     * The config the app passed, kept as it was given (the old class kept it
     * too). The bridge reads nothing from it.
     */
    public mixed $config;

    private readonly string $onGuest;

    private readonly string $accessIdCase;

    /**
     * @param mixed $config The app's old Wayne Auth config (usually the
     *        `[wayneauth]` ini section). Kept, never read: SESAME's config
     *        comes from boot().
     * @param Sesame|null $sesame The SESAME facade; the one boot() registered
     *        with sesame() when null.
     * @param string|null $onGuest `redirect` or `deny`; boot()'s value when null.
     * @param string|null $accessIdCase `asis`, `upper` or `lower`; boot()'s
     *        value when null.
     */
    public function __construct(
        mixed $config = [],
        private readonly ?Sesame $sesame = null,
        ?string $onGuest = null,
        ?string $accessIdCase = null,
    ) {
        $this->config = $config;
        $this->onGuest = self::onGuest($onGuest ?? self::$defaults['on_guest']);
        $this->accessIdCase = self::accessIdCase($accessIdCase ?? self::$defaults['accessid_case']);
    }

    /**
     * Build SESAME for a legacy app, register it with sesame(), and make the
     * old global class name `WayneAuth` point at this bridge.
     *
     * Call it once, from the app's SESAME config file, before the first
     * `new WayneAuth(...)`. The app owns the session: start it with
     * session_start() before authenticateSSO() runs (Wayne Auth needed none;
     * CAS needs one to remember the sign-in).
     *
     * The cas driver needs a PSR-18 client. Pass one, or install
     * guzzlehttp/guzzle and the bridge builds one with a 10 second timeout, redirects off and TLS
     * verification on.
     *
     * Options: `on_guest` (`redirect`, the default, or `deny`) and
     * `accessid_case` (`asis`, the default, `upper` or `lower`).
     *
     * @param array<string, mixed>|Config $config SESAME's config (see the
     *        configuration reference): `driver`, `environment` and the
     *        `cas` block.
     * @param UserResolver|null $resolver The app's resolver; by default the
     *        user is the verified username, which is all Wayne Auth gave.
     * @param array<string, mixed> $options The bridge options above.
     * @param LoggerInterface|null $logger Optional PSR-3 logger for the
     *        sign-in and sign-out events.
     * @param LoginMetrics|null $metrics Optional sign-in counter, for
     *        example a PrometheusLoginMetrics on HELMET's metrics store.
     *
     * @throws InvalidConfig When the config or an option is not valid, or
     *         no PSR-18 client can be found.
     * @throws LogicException When a different `WayneAuth` class is already
     *         loaded (the app still requires the old file).
     */
    public static function boot(
        #[\SensitiveParameter]
        array|Config $config,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?UserResolver $resolver = null,
        array $options = [],
        ?LoggerInterface $logger = null,
        ?LoginMetrics $metrics = null,
    ): Sesame {
        $config = $config instanceof Config ? $config : Config::fromArray($config);

        $onGuest = $options['on_guest'] ?? self::ON_GUEST_REDIRECT;
        $accessIdCase = $options['accessid_case'] ?? 'asis';

        if (! is_string($onGuest) || ! is_string($accessIdCase)) {
            throw new InvalidConfig('The Wayne Auth bridge options "on_guest" and "accessid_case" must be strings.');
        }

        $session = new NativeSession();
        $requests ??= new Psr17Factory();

        $sesame = new Sesame(
            static fn() => DriverFactory::make($config, $http ?? self::discoverHttpClient($config), $requests, null, null, null, $session),
            $resolver ?? self::accessIdResolver(),
            $session,
            $config,
            null,
            $logger,
            $metrics,
        );

        self::$defaults = [
            'on_guest' => self::onGuest($onGuest),
            'accessid_case' => self::accessIdCase($accessIdCase),
        ];

        sesame($sesame);
        self::aliasGlobal();
        self::reviveSessionObjects();

        return $sesame;
    }

    /**
     * Legacy apps start the session before they load Composer's autoloader
     * (the old class needed no autoloader). PHP then reads SESAME's stored
     * Identity as an incomplete object, and the visitor would look signed
     * out on every page. Once the autoloader is here, each such SESAME value
     * is read again, which restores its class.
     */
    private static function reviveSessionObjects(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        foreach ($_SESSION as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'sesame.') && $value instanceof \__PHP_Incomplete_Class) {
                $_SESSION[$key] = unserialize(serialize($value));
            }
        }
    }

    /**
     * Make `$name` (the old global class name) an alias of this bridge.
     *
     * Already the bridge: nothing to do. Some other class by that name
     * already loaded: the app still loads the old Wayne Auth file, and
     * silently keeping it would mean the swap never happened, so this throws.
     *
     * @internal Public so the guard can be tested; apps call boot().
     */
    public static function aliasGlobal(string $name = 'WayneAuth'): void
    {
        if (class_exists($name, false)) {
            if (is_a($name, self::class, true)) {
                return;
            }

            throw new LogicException(sprintf(
                'A class named %s is already loaded, so the SESAME bridge cannot take its name. Remove the line that loads the old Wayne Auth class; the SESAME config file replaces it.',
                $name,
            ));
        }

        class_alias(self::class, $name);
    }

    /**
     * Check the visitor's sign-in, the way Wayne Auth's authenticateSSO() did.
     *
     * Returns true when someone is signed in (and sets the AccessID).
     * Otherwise:
     * - a request carrying a CAS ticket completes the sign-in, then sends the
     *   browser on to the service URL without the ticket and stops the
     *   script; a ticket that does not validate throws AuthenticationFailed;
     * - a guest is sent to the CAS sign-in and the script stops (or, booted
     *   with `on_guest => deny`, false is returned and nothing is sent);
     *   a guest's script request (Accept: JSON, or X-Requested-With:
     *   XMLHttpRequest) gets a 401 with `{"signedIn":false}` instead of the
     *   redirect, and the script stops;
     * - false when the app's resolver refused the identity.
     *
     * A session past its idle (120 minutes) or absolute (12 hours) timeout
     * is a guest again: it is signed out, a fresh session takes its place,
     * and the steps above apply.
     *
     * @throws ProviderUnavailable When the CAS server cannot be reached to
     *         check a ticket, or does not answer in time (a subclass of
     *         AuthenticationFailed: catch it first to answer 503).
     * @throws AuthenticationFailed When a CAS ticket does not validate.
     */
    public function authenticateSSO(): bool
    {
        self::sayDeprecated();

        $sesame = $this->sesame();
        $request = $this->request();

        if (isset($request->getQueryParams()['ticket'])) {
            $user = $sesame->login($request);

            if ($user instanceof Redirect) {
                $this->redirect($user->url);
            }

            if ($user === null) {
                $this->accessid = null;

                return false;
            }

            $this->redirect($sesame->returnTo() ?? $sesame->serviceUrl() ?? '/');
        }

        if ($this->readAccessId($sesame) !== null) {
            return true;
        }

        if ($this->onGuest === self::ON_GUEST_DENY) {
            return false;
        }

        // A script's request (an XHR save from a page whose session timed
        // out) gets a 401 it can read, not a redirect to the CAS page it
        // cannot follow. The next page load goes through CAS as usual.
        if (JsonGuest::expected($request)) {
            $this->unauthorized();
        }

        $step = $sesame->login($request);

        if ($step instanceof Redirect) {
            $this->redirect($step->url);
        }

        // A driver that signs in without a redirect (the fake driver, in
        // local development) has stored the user by now.
        return $this->readAccessId($sesame) !== null;
    }

    /**
     * The signed-in user's AccessID, set by authenticateSSO(), or null.
     */
    public function getAccessID(): ?string
    {
        return $this->accessid;
    }

    /**
     * The old low-level check, kept for apps that called it directly.
     *
     * `v` (verify) never redirects: it answers `['verified' => true,
     * 'accessid' => ..., 'timeout' => seconds left]` for a signed-in visitor
     * and `['verified' => false]` for anyone else. The timeout is the time
     * left before SESAME's idle or absolute timeout, whichever comes first.
     *
     * `d` (decrypt) handed the app the user's password. CAS never gives an
     * app the password, so it throws: an app that needs the password needs a
     * different design, not a bridge.
     *
     * @return array{verified: true, accessid: string, timeout: int}|array{verified: false}
     *
     * @throws LogicException For `d`.
     * @throws InvalidArgumentException For any other method.
     */
    public function _parseSSOCookie(string $method): array
    {
        self::sayDeprecated();

        if ($method === 'd') {
            throw new LogicException('Wayne Auth\'s "d" method returned the user\'s password. CAS never hands an app the password, so the SESAME bridge cannot do this; see "Moving off Wayne Auth" in the SESAME docs.');
        }

        if ($method !== 'v') {
            throw new InvalidArgumentException(sprintf('Invalid SSO service method "%s": only "v" is supported.', $method));
        }

        $status = $this->sesame()->sessionStatus();

        if (! $status['signedIn']) {
            $this->accessid = null;

            return ['verified' => false];
        }

        $this->accessid = $this->cased($status['username']);
        $expires = min(
            (int) strtotime($status['idleExpiresAt']),
            (int) strtotime($status['absoluteExpiresAt']),
        );

        return [
            'verified' => true,
            'accessid' => $this->accessid,
            'timeout' => max(0, $expires - $this->now()),
        ];
    }

    /**
     * The request to answer. Built from the globals, as sesame_login() does.
     */
    protected function request(): ServerRequestInterface
    {
        return sesame_request();
    }

    /**
     * Send the browser on and stop the script, so no protected code runs.
     */
    protected function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);

        exit;
    }

    /**
     * Answer a script's request with 401 and `{"signedIn":false}`, and stop
     * the script, so no protected code runs.
     */
    protected function unauthorized(): never
    {
        JsonGuest::send();
    }

    /**
     * The current Unix time, for the `timeout` _parseSSOCookie() reports.
     */
    protected function now(): int
    {
        return time();
    }

    private function sesame(): Sesame
    {
        return $this->sesame ?? sesame();
    }

    /**
     * Read the AccessID from the SESAME session, through sessionStatus(), so
     * the idle and absolute timeouts apply exactly as they do natively.
     *
     * @phpstan-impure It reads the session, which a sign-in just changed.
     */
    private function readAccessId(Sesame $sesame): ?string
    {
        $status = $sesame->sessionStatus();

        $this->accessid = $status['signedIn'] ? $this->cased($status['username']) : null;

        return $this->accessid;
    }

    private function cased(string $accessId): string
    {
        return match ($this->accessIdCase) {
            'upper' => strtoupper($accessId),
            'lower' => strtolower($accessId),
            default => $accessId,
        };
    }

    private static function onGuest(string $value): string
    {
        if (! in_array($value, self::ON_GUEST, true)) {
            throw new InvalidConfig(sprintf('The Wayne Auth bridge option "on_guest" must be "redirect" or "deny", got "%s".', $value));
        }

        return $value;
    }

    private static function accessIdCase(string $value): string
    {
        if (! in_array($value, self::ACCESSID_CASES, true)) {
            throw new InvalidConfig(sprintf('The Wayne Auth bridge option "accessid_case" must be "asis", "upper" or "lower", got "%s".', $value));
        }

        return $value;
    }

    /**
     * The default resolver: the user is the verified username, which is all
     * Wayne Auth ever told an app.
     */
    private static function accessIdResolver(): UserResolver
    {
        return new class implements UserResolver {
            public function findOrImportUser(Identity $identity): mixed
            {
                return $identity->username;
            }
        };
    }

    /**
     * A PSR-18 client for the cas driver when the app passed none: Guzzle,
     * when it is installed. Other drivers (fake in local development) need
     * none.
     */
    private static function discoverHttpClient(Config $config): ?ClientInterface
    {
        if ($config->driver !== 'cas') {
            return null;
        }

        if (class_exists(GuzzleClient::class)) {
            // The settings the cas driver guide asks of any client: a total
            // timeout, no redirects on the validation call, TLS verified.
            return new GuzzleClient(['timeout' => 10, 'connect_timeout' => 5, 'allow_redirects' => false, 'verify' => true]);
        }

        throw new InvalidConfig('The Wayne Auth bridge needs a PSR-18 client for the cas driver. Install guzzlehttp/guzzle, or pass $http to WayneAuth::boot().');
    }

    /**
     * Say once per process, as a silenced E_USER_DEPRECATED, that the app
     * runs on the bridge. It reaches the app's error log or handler without
     * ever printing into a page or breaking a redirect.
     */
    private static function sayDeprecated(): void
    {
        if (self::$deprecationSaid) {
            return;
        }

        self::$deprecationSaid = true;

        @trigger_error('The app signs in through SESAME\'s Wayne Auth bridge (Waynestate\\Sesame\\WayneAuth\\WayneAuth). It works, and it is deprecated: move to sesame_login() and sesame_user(). See "Moving off Wayne Auth" in the SESAME docs.', E_USER_DEPRECATED);
    }

    /**
     * @internal For tests: let the next call say the deprecation again, and
     *           put boot()'s options back to their defaults.
     */
    public static function resetForTests(): void
    {
        self::$deprecationSaid = false;
        self::$defaults = ['on_guest' => self::ON_GUEST_REDIRECT, 'accessid_case' => 'asis'];
    }
}
