<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Laravel;

use GuzzleHttp\Client;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Drivers\Psr16ReplayGuard;
use Waynestate\Sesame\Drivers\ReplayGuard;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\UserResolver;

/**
 * The Laravel adapter.
 *
 * It binds the core's collaborators (the driver, the app's user resolver,
 * the session and the config) into the container, registers the auth.sesame
 * and wayne.cas middleware aliases, and, unless the app has taken the routes
 * over, registers the login and logout routes. It holds no rules of its own:
 * every rule lives in the core.
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
                    default => null,
                },
                $config->driver === 'oidc' ? $app->make(LaravelSession::class) : null,
            );
        });

        $this->app->singleton(UserResolver::class, function ($app) {
            $class = $app['config']->get('sesame.user_resolver');

            if (! is_string($class) || $class === '') {
                throw new InvalidConfig('Set sesame.user_resolver to the class that resolves a verified identity to the app\'s user (wayne-cas apps: \Waynestate\Sesame\WayneCas\UserResolver::class).');
            }

            return $app->make($class);
        });

        $this->app->singleton(Sesame::class, function ($app) {
            return new Sesame(
                $app->make(Driver::class),
                $app->make(UserResolver::class),
                $app->make(LaravelSession::class),
                $this->coreConfig($app),
                $this->clock($app),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/sesame.php' => $this->app->configPath('sesame.php'),
        ], 'sesame-config');

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
     * The login and logout routes, when the app has not taken them over.
     */
    private function routes(): void
    {
        $router = $this->app->make(Router::class);

        $oidc = $this->app->make(Repository::class)->get('sesame.driver') === 'oidc';

        $router->group(['middleware' => 'web'], function () use ($router, $oidc) {
            $login = function (Request $request) {
                $sesame = $this->app->make(Sesame::class);
                $result = $sesame->login($this->toPsr7($request));

                if ($result instanceof Redirect) {
                    return redirect($result->url);
                }

                // The oidc driver remembers a checked return-to (same host
                // as the redirect URI); every other driver uses the page
                // auth.sesame saved before it sent the guest to sign in.
                $returnTo = $sesame->returnTo();

                return $returnTo !== null ? redirect($returnTo) : redirect()->intended('/');
            };

            $router->get('/login', $login)->name('sesame.login');

            if ($oidc) {
                $router->get('/login/callback', $login)->name('sesame.callback');
            }

            $router->post('/logout', function () {
                $url = $this->app->make(Sesame::class)->logout(url('/'));

                // Keep the logout response out of any cache and clear the
                // site's cache and storage. "cookies" is deliberately left
                // out of Clear-Site-Data: it would clear every cookie on the
                // host, signing the user out of every other app on
                // wayne.edu, not just this one.
                $response = $url !== null ? redirect($url) : redirect('/');

                return $response
                    ->header('Cache-Control', 'no-store')
                    ->header('Clear-Site-Data', '"cache", "storage"');
            })->name('sesame.logout');
        });
    }

    /**
     * The PSR-7 view of the incoming request, for the core.
     */
    private function toPsr7(Request $request): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        return (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromArrays(
            $request->server->all(),
            $request->headers->all(),
            $request->cookies->all(),
            $request->query->all(),
            $request->request->all(),
            $request->files->all(),
        );
    }
}
