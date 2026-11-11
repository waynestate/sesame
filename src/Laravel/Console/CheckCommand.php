<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use ReflectionClass;
use Throwable;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Exception\FakeDriverInProduction;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\UserResolver;

/**
 * `php artisan sesame:check`: the locksmith's visit before opening day.
 *
 * In Laravel, SESAME rides on the app's own session, so `config/session.php`
 * decides whether sign-in works at all, and a wrong value fails quietly: a
 * `SameSite=Strict` cookie is left off the identity provider's return, so
 * every sign-in fails without an error anyone can read. This command reads
 * those settings and SESAME's own, prints one `[OK]`, `[WARN]` or `[FAIL]`
 * line for each, and exits 1 on any failure (or any warning with
 * `--strict`), so a deploy job can run it first.
 *
 * It only reads config and builds the driver; it never signs anyone in,
 * never calls the identity provider, and prints no URL, key or secret, so
 * its output is safe in a CI log. The front page's lock inspection prints
 * these same lines (FrontPageCheckTest holds it to them).
 */
final class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'sesame:check {--strict : Also fail on warnings}';

    /** @var string */
    protected $description = "Check this app's SESAME and session settings before a deploy.";

    public function handle(): int
    {
        /** @var Repository $config */
        $config = $this->laravel->make('config');

        $lines = [
            $this->driver($config),
            $this->userResolver($config),
            $this->sessionDriver($config),
            $this->httpOnly($config),
            $this->sameSite($config),
            $this->secure($config),
            $this->lifetime($config),
        ];

        $count = ['OK' => 0, 'WARN' => 0, 'FAIL' => 0];

        foreach ($lines as [$verdict, $text]) {
            $count[$verdict]++;
            $this->line("[{$verdict}] {$text}");
        }

        $this->line('');
        $this->line(sprintf(
            '%d ok, %d %s, %d %s.',
            $count['OK'],
            $count['WARN'],
            $count['WARN'] === 1 ? 'warning' : 'warnings',
            $count['FAIL'],
            $count['FAIL'] === 1 ? 'failure' : 'failures',
        ));

        $failed = $count['FAIL'] > 0 || ($this->option('strict') === true && $count['WARN'] > 0);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Build the driver from config, which validates it. Nothing is called on
     * it: building is the whole test.
     *
     * @return array{'OK'|'WARN'|'FAIL', string}
     */
    private function driver(Repository $config): array
    {
        $name = $config->get('sesame.driver');
        $name = is_string($name) ? $name : '';

        try {
            // A fresh build, never one an earlier call left in the container.
            if ($this->laravel instanceof Container) {
                $this->laravel->forgetInstance(Driver::class);
            }
            $this->laravel->make(Driver::class);
        } catch (InvalidConfig|FakeDriverInProduction $e) {
            // The message names the key and the fix, which is the point; a
            // URL it quotes back is replaced, since a URL can carry a user
            // and password, and this line ends up in a CI log.
            $message = (string) preg_replace('~[a-z][a-z0-9+.-]*://[^\s"\']+~i', '(a URL)', $e->getMessage());

            return ['FAIL', 'Driver: ' . $message];
        } catch (Throwable $e) {
            // Only the class: a message from deeper down might carry a URL.
            return ['FAIL', 'Driver: it could not be built (' . (new ReflectionClass($e))->getShortName() . ').'];
        }

        return ['OK', "Driver: {$name} is configured."];
    }

    /** @return array{'OK'|'WARN'|'FAIL', string} */
    private function userResolver(Repository $config): array
    {
        $class = $config->get('sesame.user_resolver');

        if (! is_string($class) || $class === '') {
            return ['FAIL', 'User resolver: set sesame.user_resolver to the class that turns a verified identity into your user.'];
        }

        if (! class_exists($class) || ! is_subclass_of($class, UserResolver::class)) {
            return ['FAIL', 'User resolver: sesame.user_resolver does not name a class that implements Waynestate\Sesame\UserResolver.'];
        }

        return ['OK', 'User resolver: set.'];
    }

    /** @return array{'OK'|'WARN'|'FAIL', string} */
    private function sessionDriver(Repository $config): array
    {
        $driver = $config->get('session.driver');
        $driver = is_string($driver) ? $driver : '';

        return match ($driver) {
            'array' => ['FAIL', 'Session driver: array ends every session with the request, so a sign-in never sticks.'],
            'cookie' => ['WARN', 'Session driver: cookie keeps the whole session in the browser; prefer file, database or redis.'],
            default => ['OK', "Session driver: {$driver}."],
        };
    }

    /** @return array{'OK'|'WARN'|'FAIL', string} */
    private function httpOnly(Repository $config): array
    {
        return $config->get('session.http_only') === true
            ? ['OK', 'Session cookie: HttpOnly.']
            : ['FAIL', 'Session cookie: set session.http_only to true (SESSION_HTTP_ONLY), so scripts cannot read the session cookie.'];
    }

    /** @return array{'OK'|'WARN'|'FAIL', string} */
    private function sameSite(Repository $config): array
    {
        $sameSite = $config->get('session.same_site');
        $sameSite = is_string($sameSite) ? strtolower($sameSite) : '';

        return match ($sameSite) {
            'lax' => ['OK', 'Session cookie: SameSite=Lax.'],
            'strict' => ['FAIL', "Session cookie: SameSite=Strict drops the cookie on the identity provider's return, so every sign-in fails; use lax."],
            'none' => ['WARN', 'Session cookie: SameSite=None sends the cookie on cross-site requests; use lax.'],
            default => ['WARN', 'Session cookie: no SameSite value; set session.same_site to lax.'],
        };
    }

    /** @return array{'OK'|'WARN'|'FAIL', string} */
    private function secure(Repository $config): array
    {
        $secure = $config->get('session.secure');

        if ($secure === true) {
            return ['OK', 'Session cookie: Secure.'];
        }

        if ($secure === null) {
            return ['WARN', "Session cookie: Secure follows each request's scheme; set SESSION_SECURE_COOKIE=true on an https app."];
        }

        return $this->laravel->environment('production') === true
            ? ['FAIL', 'Session cookie: session.secure is false in production; set SESSION_SECURE_COOKIE=true.']
            : ['WARN', 'Session cookie: session.secure is false; set SESSION_SECURE_COOKIE=true before production.'];
    }

    /** @return array{'OK'|'WARN'|'FAIL', string} */
    private function lifetime(Repository $config): array
    {
        if ($config->get('session.expire_on_close') === true) {
            return ['OK', 'Session lifetime: the session ends when the browser closes.'];
        }

        $laravel = $this->minutes($config->get('session.lifetime'), 120);
        $idle = $this->minutes($config->get('sesame.session.idle_minutes'), 120);

        return $laravel < $idle
            ? ['WARN', "Session lifetime: Laravel drops a quiet session after {$laravel} minutes, before SESAME's {$idle} minute idle timeout."]
            : ['OK', "Session lifetime: {$laravel} minutes, SESAME's idle timeout is {$idle}."];
    }

    /** A minute count from config, which may arrive as an int or a numeric string from env(). */
    private function minutes(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
