<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Illuminate\Testing\PendingCommand;

/**
 * `php artisan sesame:check`, line by line: what each setting earns, what
 * the summary counts, and when the exit code stops a deploy. The baseline
 * is a sound app on the fake driver; each test breaks one thing, like a
 * locksmith jiggling one pin at a time.
 */
final class CheckCommandTest extends LaravelTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];

        $config->set('session.driver', 'file');
        $config->set('session.http_only', true);
        $config->set('session.same_site', 'lax');
        $config->set('session.secure', true);
        $config->set('session.lifetime', 120);
        $config->set('session.expire_on_close', false);
    }

    private function set(string $key, mixed $value): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app->make('config');
        $config->set($key, $value);
    }

    private function production(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $app->detectEnvironment(static fn(): string => 'production');
    }

    /** @param array<string, bool> $options */
    private function check(array $options = []): PendingCommand
    {
        $command = $this->artisan('sesame:check', $options);
        self::assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    public function testASoundAppIsAllOk(): void
    {
        $this->check()
            ->expectsOutput('[OK] Driver: fake is configured.')
            ->expectsOutput('[OK] User resolver: set.')
            ->expectsOutput('[OK] Session driver: file.')
            ->expectsOutput('[OK] Session cookie: HttpOnly.')
            ->expectsOutput('[OK] Session cookie: SameSite=Lax.')
            ->expectsOutput('[OK] Session cookie: Secure.')
            ->expectsOutput("[OK] Session lifetime: 120 minutes, SESAME's idle timeout is 120.")
            ->expectsOutput('7 ok, 0 warnings, 0 failures.')
            ->assertExitCode(0);
    }

    public function testSameSiteStrictFails(): void
    {
        $this->set('session.same_site', 'strict');

        $this->check()
            ->expectsOutput("[FAIL] Session cookie: SameSite=Strict drops the cookie on the identity provider's return, so every sign-in fails; use lax.")
            ->expectsOutput('6 ok, 0 warnings, 1 failure.')
            ->assertExitCode(1);
    }

    public function testSameSiteIsComparedWithoutCase(): void
    {
        $this->set('session.same_site', 'Strict');

        $this->check()
            ->expectsOutputToContain('[FAIL] Session cookie: SameSite=Strict')
            ->assertExitCode(1);
    }

    public function testSameSiteNoneWarnsAndStrictModeFailsOnIt(): void
    {
        $this->set('session.same_site', 'none');

        $this->check()
            ->expectsOutput('[WARN] Session cookie: SameSite=None sends the cookie on cross-site requests; use lax.')
            ->expectsOutput('6 ok, 1 warning, 0 failures.')
            ->assertExitCode(0);

        $this->check(['--strict' => true])
            ->expectsOutput('[WARN] Session cookie: SameSite=None sends the cookie on cross-site requests; use lax.')
            ->assertExitCode(1);
    }

    public function testNoSameSiteValueWarns(): void
    {
        $this->set('session.same_site', null);

        $this->check()
            ->expectsOutput('[WARN] Session cookie: no SameSite value; set session.same_site to lax.')
            ->assertExitCode(0);
    }

    public function testHttpOnlyOffFails(): void
    {
        $this->set('session.http_only', false);

        $this->check()
            ->expectsOutput('[FAIL] Session cookie: set session.http_only to true (SESSION_HTTP_ONLY), so scripts cannot read the session cookie.')
            ->assertExitCode(1);
    }

    public function testTheArraySessionDriverFails(): void
    {
        $this->set('session.driver', 'array');

        $this->check()
            ->expectsOutput('[FAIL] Session driver: array ends every session with the request, so a sign-in never sticks.')
            ->assertExitCode(1);
    }

    public function testTheCookieSessionDriverWarns(): void
    {
        $this->set('session.driver', 'cookie');

        $this->check()
            ->expectsOutput('[WARN] Session driver: cookie keeps the whole session in the browser; prefer file, database or redis.')
            ->assertExitCode(0);
    }

    public function testSecureUnsetWarns(): void
    {
        $this->set('session.secure', null);

        $this->check()
            ->expectsOutput("[WARN] Session cookie: Secure follows each request's scheme; set SESSION_SECURE_COOKIE=true on an https app.")
            ->assertExitCode(0);
    }

    public function testSecureFalseOutsideProductionWarns(): void
    {
        $this->set('session.secure', false);

        $this->check()
            ->expectsOutput('[WARN] Session cookie: session.secure is false; set SESSION_SECURE_COOKIE=true before production.')
            ->assertExitCode(0);
    }

    public function testSecureFalseInProductionFails(): void
    {
        $this->production();
        $this->set('sesame.driver', 'cas');
        $this->set('sesame.cas.base_url', 'https://login.example.edu/cas');
        $this->set('sesame.cas.service_url', 'https://app.example.edu/login');
        $this->set('session.secure', false);

        $this->check()
            ->expectsOutput('[OK] Driver: cas is configured.')
            ->expectsOutput('[FAIL] Session cookie: session.secure is false in production; set SESSION_SECURE_COOKIE=true.')
            ->expectsOutput('6 ok, 0 warnings, 1 failure.')
            ->assertExitCode(1);
    }

    public function testTheFakeDriverInProductionFails(): void
    {
        $this->production();

        $this->check()
            ->expectsOutput('[FAIL] Driver: The fake driver only runs in local, development and testing environments.')
            ->assertExitCode(1);
    }

    public function testACasDriverWithoutItsServerFails(): void
    {
        $this->set('sesame.driver', 'cas');
        $this->set('sesame.cas.base_url', null);

        $this->check()
            ->expectsOutputToContain('[FAIL] Driver: ')
            ->doesntExpectOutputToContain('[OK] Driver:')
            ->assertExitCode(1);
    }

    public function testAnEmptyUserResolverFails(): void
    {
        $this->set('sesame.user_resolver', '');

        $this->check()
            ->expectsOutput('[FAIL] User resolver: set sesame.user_resolver to the class that turns a verified identity into your user.')
            ->assertExitCode(1);
    }

    public function testAUserResolverThatIsNotOneFails(): void
    {
        $this->set('sesame.user_resolver', \stdClass::class);

        $this->check()
            ->expectsOutput('[FAIL] User resolver: sesame.user_resolver does not name a class that implements Waynestate\Sesame\UserResolver.')
            ->assertExitCode(1);

        $this->set('sesame.user_resolver', 'App\\Nowhere\\Resolver');

        $this->check()
            ->expectsOutput('[FAIL] User resolver: sesame.user_resolver does not name a class that implements Waynestate\Sesame\UserResolver.')
            ->assertExitCode(1);
    }

    public function testALaravelLifetimeShorterThanTheIdleTimeoutWarns(): void
    {
        $this->set('session.lifetime', 30);

        $this->check()
            ->expectsOutput("[WARN] Session lifetime: Laravel drops a quiet session after 30 minutes, before SESAME's 120 minute idle timeout.")
            ->assertExitCode(0);
    }

    public function testALifetimeFromEnvIsReadAsMinutes(): void
    {
        $this->set('session.lifetime', '240');

        $this->check()
            ->expectsOutput("[OK] Session lifetime: 240 minutes, SESAME's idle timeout is 120.")
            ->assertExitCode(0);
    }

    public function testExpireOnCloseIsOk(): void
    {
        $this->set('session.lifetime', 30);
        $this->set('session.expire_on_close', true);

        $this->check()
            ->expectsOutput('[OK] Session lifetime: the session ends when the browser closes.')
            ->assertExitCode(0);
    }

    public function testWarningsAndFailuresAreCountedApart(): void
    {
        $this->set('session.same_site', 'none');
        $this->set('session.driver', 'array');
        $this->set('session.lifetime', 30);

        $this->check()
            ->expectsOutput('4 ok, 2 warnings, 1 failure.')
            ->assertExitCode(1);
    }

    public function testTheOutputCarriesNoUrlOrSecret(): void
    {
        $this->set('sesame.driver', 'cas');
        $this->set('sesame.cas.base_url', 'https://login.example.edu/cas');
        $this->set('sesame.cas.service_url', 'https://app.example.edu/login');

        $this->check()
            ->doesntExpectOutputToContain('login.example.edu')
            ->doesntExpectOutputToContain('base64:')
            ->assertExitCode(0);
    }

    public function testAUrlInADriverErrorIsNotPrinted(): void
    {
        // An http return URL is refused, and the refusal quotes it back; the
        // line keeps the key and the rule, never the URL (or a password in it).
        $this->set('sesame.driver', 'cas');
        $this->set('sesame.cas.base_url', 'https://login.example.edu/cas');
        $this->set('sesame.cas.service_url', 'http://app.example.edu/login');

        $this->check()
            ->expectsOutput('[FAIL] Driver: The config key "cas.service_url" must be an absolute https:// URL, got "(a URL)".')
            ->doesntExpectOutputToContain('app.example.edu')
            ->assertExitCode(1);

        $this->set('sesame.cas.service_url', 'https://deploy:hunter2@app.example.edu/login');

        $this->check()
            ->expectsOutputToContain('[FAIL] Driver: ')
            ->doesntExpectOutputToContain('hunter2')
            ->assertExitCode(1);
    }
}
