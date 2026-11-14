<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Laravel;

use GuzzleHttp\Client;
use Illuminate\Auth\AuthManager;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Cookie\QueueingFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Adapter;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Drivers\Psr16ReplayGuard;
use Waynestate\Sesame\Drivers\ReplayGuard;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Helmet\Metrics\MetricsStorage;
use Waynestate\Sesame\Laravel\Console\CheckCommand;
use Waynestate\Sesame\Laravel\Http\SesameController;
use Waynestate\Sesame\Metrics\LoginMetrics;
use Waynestate\Sesame\Metrics\PrometheusLoginMetrics;
use Waynestate\Sesame\Metrics\UnavailableLoginMetrics;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\UserResolver;

/**
 * The Laravel adapter.
 *
 * It binds the core's collaborators (the driver, the app's user resolver,
 * the session and the config) into the container, registers the sesame guard
 * driver, the auth.sesame and wayne.cas middleware aliases, and, unless the
 * app has taken the routes over, registers the login and logout routes. It
 * holds no rules of its own: every rule lives in the core.
 */
class SesameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/sesame.php', 'sesame');

        $this->app->singleton(LaravelSession::class, function ($app) {
            return new LaravelSession($app->make(Session::class));
        });

        $this->app->singleton(ReplayGuard::class, function ($app) {
            $store = $app['config']->get('sesame.gateway.replay_store');
            $leeway = (int) $app['config']->get('sesame.gateway.leeway_seconds', 30);

            // The guard shares the driver's clock, so the record's TTL is
            // measured against the same "now" the driver uses to check the
            // assertion's time claims.
            return new Psr16ReplayGuard(
                new LaravelPsr16Cache($app['cache']->store(is_string($store) && $store !== '' ? $store : null)),
                $leeway,
                $this->clock($app),
            );
        });

        $this->app->singleton(Driver::class, function ($app) {
            $config = $this->coreConfig($app);

            return DriverFactory::make(
                $config,
                $this->httpClient($app),
                $this->requestFactory($app),
                $this->clock($app),
                $config->driver === 'gateway' ? $app->make(ReplayGuard::class) : null,
                match ($config->driver) {
                    'oauth2' => $this->jwksCache($app, 'sesame.oauth2.jwks_store'),
                    'oidc' => $this->jwksCache($app, 'sesame.oidc.cache_store'),
                    'saml' => $this->jwksCache($app, 'sesame.saml.cache_store'),
                    default => null,
                },
                // The oidc and saml drivers keep their sign-in state in the
                // session, and the cas driver keeps its pending-login marker
                // there.
                in_array($config->driver, ['oidc', 'cas', 'saml'], true) ? $app->make(LaravelSession::class) : null,
            );
        });

        $this->app->singleton(UserResolver::class, function ($app) {
            $class = $app['config']->get('sesame.user_resolver');

            if (! is_string($class) || $class === '') {
                throw new InvalidConfig('Set sesame.user_resolver to the class that resolves a verified identity to the app\'s user (a model with a static findOrImportUser: \Waynestate\Sesame\Laravel\ModelUserResolver::class).');
            }

            return $app->make($class);
        });

        $this->app->singleton(Sesame::class, function ($app) {
            // The driver goes in as a factory: building it validates the
            // config, and an app with a broken config should still boot and
            // answer signed-out requests.
            return new Sesame(
                static fn(): Driver => $app->make(Driver::class),
                $app->make(UserResolver::class),
                $app->make(LaravelSession::class),
                $this->coreConfig($app),
                $this->clock($app),
                $this->logger($app),
                $this->loginMetrics($app),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/sesame.php' => $this->app->configPath('sesame.php'),
        ], 'sesame-config');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
        }

        // The sesame guard driver: auth.driver = "sesame" gives the app's
        // guards a view of the SESAME session. It holds no rules of its own;
        // the rules live in the core, and the driver only reads them.
        $this->app->make(AuthManager::class)->extend('sesame', function (\Illuminate\Foundation\Application $app, string $name, array $config) {
            /** @var UserProvider $provider */
            $provider = $app->make(AuthManager::class)->createUserProvider($config['provider'] ?? null);

            /** @var Session $session */
            $session = $app['session.store'];

            /** @var \Symfony\Component\HttpFoundation\Request $request */
            $request = $app['request'];

            $guard = new SesameGuard($name, $provider, $session, $request);

            /** @var QueueingFactory $cookies */
            $cookies = $app['cookie'];

            /** @var Dispatcher $events */
            $events = $app['events'];

            $guard->setCookieJar($cookies);
            $guard->setDispatcher($events);

            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('auth.sesame', Authenticate::class);
        $router->aliasMiddleware('sesame.mfa', RequireMfa::class);
        $router->aliasMiddleware('sesame.context', RequireAuthContext::class);
        $router->aliasMiddleware('wayne.cas', \Waynestate\Sesame\WayneCas\WayneAuthenticate::class);

        if ((bool) $this->app->make(Repository::class)->get('sesame.routes', true)) {
            $this->routes();
        }
    }

    /**
     * The core's config, built from the app's sesame config.
     *
     * The environment comes from the app, and the cas service_url falls back
     * to the app's /login route when the app has not set one.
     */
    private function coreConfig(Application $app): Config
    {
        $config = $app->make(Repository::class)->get('sesame', []);

        $config['environment'] = $app->environment();

        if (($config['cas']['service_url'] ?? null) === null) {
            $config['cas']['service_url'] = $app->make(UrlGenerator::class)->to('/login');
        }

        if (($config['driver'] ?? null) === 'oidc' && ($config['oidc']['redirect_uri'] ?? null) === null) {
            $config['oidc']['redirect_uri'] = $app->make(UrlGenerator::class)->to('/login/callback');
        }

        // The saml driver's URLs default to the routes this adapter
        // registers: the login URL, the ACS and the SP metadata (whose URL
        // is the SP's entity ID, as Shibboleth does it).
        if (($config['driver'] ?? null) === 'saml') {
            $urls = $app->make(UrlGenerator::class);
            $config['saml']['service_url'] ??= $urls->to('/login');
            $config['saml']['sp']['acs_url'] ??= $urls->to('/login/saml/acs');
            $config['saml']['sp']['entity_id'] ??= $urls->to('/login/saml/metadata');
        }

        return Config::fromArray($config);
    }

    /**
     * The PSR-18 client the cas and oauth2 drivers use: the app's binding
     * when there is one, Guzzle when it is installed, and nothing when there
     * is neither.
     */
    private function httpClient(Application $app): ?ClientInterface
    {
        if ($app->bound(ClientInterface::class)) {
            return $app->make(ClientInterface::class);
        }

        if (class_exists(Client::class)) {
            // Redirects off: SESAME never follows a redirect from an issuer,
            // a token endpoint or a key set (PSR-18 sendRequest() already
            // ignores them; this covers send() too).
            return new Client(['allow_redirects' => false]);
        }

        return null;
    }

    /**
     * The PSR-16 cache the oauth2 and oidc drivers keep their fetched key
     * set (and, for oidc, the discovery document) in: the app's store named
     * by $key, or the default store when none is named.
     */
    private function jwksCache(Application $app, string $key): CacheInterface
    {
        $store = $app->make(Repository::class)->get($key);
        $cache = $app->make(CacheManager::class)->store(is_string($store) && $store !== '' ? $store : null);

        if (! $cache instanceof \Illuminate\Cache\Repository) {
            throw new InvalidConfig(sprintf('The %s cache store must be a standard Laravel cache store.', $key));
        }

        return new LaravelPsr16Cache($cache);
    }

    /**
     * The PSR-17 request factory the cas and oauth2 drivers use.
     */
    private function requestFactory(Application $app): RequestFactoryInterface
    {
        if ($app->bound(RequestFactoryInterface::class)) {
            return $app->make(RequestFactoryInterface::class);
        }

        return new Psr17Factory();
    }

    /**
     * The PSR-20 clock the core uses for the session timeouts and the cas
     * driver's sign-in time: the app's binding when there is one, and the
     * system clock when there is not.
     */
    private function clock(Application $app): ?ClockInterface
    {
        if ($app->bound(ClockInterface::class)) {
            return $app->make(ClockInterface::class);
        }

        return null;
    }

    /**
     * The PSR-3 logger the core logs its sign-in and sign-out events
     * through: the app's binding when there is one and sesame.log is on,
     * and nothing when there is neither or the app has turned logging off.
     */
    private function logger(Application $app): ?LoggerInterface
    {
        if (! (bool) $app->make(Repository::class)->get('sesame.log', true)) {
            return null;
        }

        if ($app->bound(LoggerInterface::class)) {
            return $app->make(LoggerInterface::class);
        }

        return null;
    }

    /**
     * The sign-in counter (ADR-0011), when sesame.metrics.enabled is on.
     *
     * The store is the app's Prometheus\Storage\Adapter binding when there
     * is one, else the store HELMET's request metrics use (helmet.metrics),
     * so sesame_logins_total shows up on HELMET's /metrics without a route
     * of SESAME's own. The store is built on the first sign-in, not here:
     * a request that signs nobody in never opens it. When there is no
     * store, or promphp is not installed, the first sign-in logs
     * `sesame.metrics_failed` with the reason, and signs the user in anyway.
     */
    private function loginMetrics(Application $app): ?LoginMetrics
    {
        $config = $app->make(Repository::class);

        if (! filter_var($config->get('sesame.metrics.enabled', false), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        if (! class_exists(CollectorRegistry::class)) {
            return new UnavailableLoginMetrics('SESAME_METRICS_ENABLED is on, but promphp/prometheus_client_php is not installed: install HELMET (which requires it) or the library itself.');
        }

        return new PrometheusLoginMetrics(static function () use ($app, $config): ?Adapter {
            if ($app->bound(Adapter::class)) {
                return $app->make(Adapter::class);
            }

            $helmet = (array) $config->get('helmet.metrics', []);
            $storage = $helmet['storage'] ?? null;

            if (! class_exists(MetricsStorage::class)
                || ! filter_var($helmet['enabled'] ?? false, FILTER_VALIDATE_BOOL)
                || ! is_string($storage)) {
                return null;
            }

            return MetricsStorage::create(
                $storage,
                is_string($helmet['redis_url'] ?? null) ? $helmet['redis_url'] : null,
                is_string($helmet['sqlite_path'] ?? null) ? $helmet['sqlite_path'] : null,
            );
        });
    }

    /**
     * The login and logout routes, when the app has not taken them over.
     *
     * Every action is a method on SesameController, not a closure: a
     * closure route is serialized with everything it captures when the
     * app caches its routes, and the old closures captured the container,
     * which is what made `php artisan route:cache` run out of memory in an
     * app that runs `optimize` (which every Citadel release does).
     */
    private function routes(): void
    {
        $router = $this->app->make(Router::class);

        $driver = $this->app->make(Repository::class)->get('sesame.driver');
        $oidc = $driver === 'oidc';

        if ($driver === 'saml') {
            $this->samlRoutes($router);
        }

        $router->group(['middleware' => 'web'], function () use ($router, $oidc) {
            $router->get('/login', [SesameController::class, 'login'])->name('sesame.login');

            if ($oidc) {
                $router->get('/login/callback', [SesameController::class, 'login'])->name('sesame.callback');
            }

            $router->post('/logout', [SesameController::class, 'logout'])->name('sesame.logout');

            // The single-page app's "who am I": the session facts as JSON,
            // 200 when someone is signed in, 401 when no one is, never
            // cacheable. The app's own user fields come from a closure it
            // bound as sesame.describe_user; without one the answer's user
            // field is null. A binding of anything other than a closure
            // reads as no extra fields, not an error.
            $router->get('/sesame/status', [SesameController::class, 'status'])->name('sesame.status');
        });
    }

    /**
     * The saml driver's two routes, outside the web group.
     *
     * The ACS takes the IdP's cross-site POST, which carries no Lax session
     * cookie and no CSRF token: with the web group's middleware it would
     * fail the CSRF check, and starting a session there would replace the
     * user's real one. So it runs without a session; the driver parks the
     * response and the 303 sends the browser to /login, where the session
     * is back and the response is checked. The metadata route needs no
     * session either.
     */
    private function samlRoutes(Router $router): void
    {
        $router->post('/login/saml/acs', [SesameController::class, 'samlAcs'])->name('sesame.saml.acs');

        $router->get('/login/saml/metadata', [SesameController::class, 'samlMetadata'])->name('sesame.saml.metadata');
    }
}
