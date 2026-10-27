<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\RecordingDriver;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

final class FunctionsTest extends TestCase
{
    public function testSesameReturnsTheInstanceItWasGiven(): void
    {
        $instance = new Sesame(
            new FakeDriver('local', 'jdoe', []),
            new RecordingResolver(),
            new RecordingSession(new EventLog()),
        );

        $this->assertSame($instance, sesame($instance));
        $this->assertSame($instance, sesame());
    }

    public function testSesameRequestBuildsTheRequestFromGlobals(): void
    {
        $server = $_SERVER;
        $get = $_GET;

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/login?ticket=ST-1';
        $_SERVER['HTTP_HOST'] = 'app.test';
        $_GET = ['ticket' => 'ST-1'];

        try {
            $request = sesame_request();
        } finally {
            $_SERVER = $server;
            $_GET = $get;
        }

        $this->assertSame('ST-1', $request->getQueryParams()['ticket']);
    }

    public function testSesameLoginStopsTheScriptAfterARedirect(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\RecordingDriver;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

$log = new EventLog();
$sesame = new Sesame(
    new RecordingDriver(new Redirect('http://idp.local/login?service=x'), null, $log),
    new RecordingResolver(),
    new RecordingSession($log),
);
sesame($sesame);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/login';
$_SERVER['HTTP_HOST'] = 'app.test';
$_GET = [];

sesame_login();

echo 'CONTINUED_AFTER_REDIRECT';
PHP;

        $output = $this->runChildPhp($this->childScript($body));

        $this->assertStringNotContainsString('CONTINUED_AFTER_REDIRECT', $output);
    }

    public function testSesameLogoutStopsTheScriptAfterARedirect(): void
    {
        $body = <<<'PHP'
use DateTimeImmutable;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\RecordingDriver;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

$log = new EventLog();
$identity = new Identity('jdoe', [], 'fake', new DateTimeImmutable());
$sesame = new Sesame(
    new RecordingDriver(new Authenticated($identity), 'http://idp.local/logout', $log),
    new RecordingResolver(),
    new RecordingSession($log),
);
sesame($sesame);

sesame_logout('http://app.local/');

echo 'CONTINUED_AFTER_REDIRECT';
PHP;

        $output = $this->runChildPhp($this->childScript($body));

        $this->assertStringNotContainsString('CONTINUED_AFTER_REDIRECT', $output);
    }

    public function testSafeReturnToKeepsAnHttpsReturnToOnTheServiceHost(): void
    {
        $sesame = $this->sesameWithServiceUrl('https://app.test/login');

        $this->assertSame('https://app.test/dashboard', $sesame->safeReturnTo('https://app.test/dashboard'));
    }

    public function testSafeReturnToFallsBackToTheServiceUrlForAnOffHostReturnTo(): void
    {
        $sesame = $this->sesameWithServiceUrl('https://app.test/login');

        $this->assertSame('https://app.test/login', $sesame->safeReturnTo('https://evil.test/'));
    }

    public function testSafeReturnToFallsBackToTheServiceUrlForAnHttpReturnTo(): void
    {
        $sesame = $this->sesameWithServiceUrl('https://app.test/login');

        $this->assertSame('https://app.test/login', $sesame->safeReturnTo('http://app.test/'));
    }

    public function testSafeReturnToFallsBackToTheServiceUrlForARelativeReturnTo(): void
    {
        $sesame = $this->sesameWithServiceUrl('https://app.test/login');

        $this->assertSame('https://app.test/login', $sesame->safeReturnTo('/dashboard'));
    }
    public function testSafeReturnToComparesHostsWithoutCase(): void
    {
        $sesame = $this->sesameWithServiceUrl('https://app.example.edu/login');

        $this->assertSame('https://APP.Example.EDU/after-login', $sesame->safeReturnTo('https://APP.Example.EDU/after-login'));
    }

    /**
     * A Sesame with a recording driver that has no logout URL but a service URL.
     */
    private function sesameWithServiceUrl(string $serviceUrl): Sesame
    {
        $identity = new Identity('jdoe', [], 'fake', new DateTimeImmutable());

        return new Sesame(
            new RecordingDriver(new Authenticated($identity), null, new EventLog(), $serviceUrl),
            new RecordingResolver(),
            new RecordingSession(new EventLog()),
        );
    }

    /**
     * Wrap a snippet in a runnable script that loads the autoloader first.
     */
    private function childScript(string $body): string
    {
        $autoloader = dirname(__DIR__).'/vendor/autoload.php';

        return "<?php\ndeclare(strict_types=1);\nrequire ".var_export($autoloader, true).";\n\n".$body;
    }

    /**
     * Run a script in a child PHP process and return its combined output.
     *
     * A child that reaches its marker line did not stop after the redirect,
     * so the tests assert the marker is absent.
     */
    private function runChildPhp(string $script): string
    {
        $file = tempnam(sys_get_temp_dir(), 'sesame-child-').'.php';
        file_put_contents($file, $script);

        $process = proc_open(
            [PHP_BINARY, $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            unlink($file);
            $this->fail('Could not start a child PHP process.');
        }

        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($file);

        return $output;
    }
}
