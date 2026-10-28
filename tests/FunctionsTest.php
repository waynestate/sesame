<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\RecordingDriver;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

final class FunctionsTest extends TestCase
{
    private function config(): Config
    {
        return new Config(
            driver: 'fake',
            environment: 'local',
            fakeUsername: 'jdoe',
        );
    }

    public function testSesameReturnsTheInstanceItWasGiven(): void
    {
        $instance = new Sesame(
            new FakeDriver('local', 'jdoe', []),
            new RecordingResolver(),
            new RecordingSession(new EventLog()),
            $this->config(),
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
use Waynestate\Sesame\Config;
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
    new Config(driver: 'fake', environment: 'local', fakeUsername: 'jdoe'),
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

    public function testSesameLoginRedirectsToTheReturnToAfterACasLogin(): void
    {
        [$process, $port, $pipes, $file] = $this->startServer($this->loginRouterScript());

        try {
            [$status, $headers] = $this->httpGet('http://127.0.0.1:'.$port.'/login?ticket=ST-1');
        } finally {
            $this->stopServer($process, $pipes, $file);
        }

        $this->assertSame(302, $status);
        $this->assertSame('/dashboard', $headers['location'] ?? null);
        $this->assertStringNotContainsString('ticket', (string) ($headers['location'] ?? ''));
    }

    public function testSesameLogoutSendsNoStoreAndClearSiteData(): void
    {
        [$process, $port, $pipes, $file] = $this->startServer($this->logoutRouterScript());

        try {
            [$status, $headers] = $this->httpGet('http://127.0.0.1:'.$port.'/logout');
        } finally {
            $this->stopServer($process, $pipes, $file);
        }

        $this->assertSame(302, $status);
        $this->assertSame('no-store', $headers['cache-control'] ?? null);
        $this->assertSame('"cache", "storage"', $headers['clear-site-data'] ?? null);
        $this->assertStringNotContainsString('cookies', $headers['clear-site-data'] ?? '');
    }

    public function testSesameLogoutStopsTheScriptAfterARedirect(): void
    {
        $body = <<<'PHP'
use DateTimeImmutable;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
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
    new Config(driver: 'fake', environment: 'local', fakeUsername: 'jdoe'),
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
            $this->config(),
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

    /**
     * The router script for the built-in server: it boots SESAME with the cas
     * driver and a fake HTTP client that answers the ticket with a success,
     * then runs the raw login, which must end in a redirect to the return-to.
     */
    private function loginRouterScript(): string
    {
        $root = dirname(__DIR__);
        $autoloader = $root.'/vendor/autoload.php';
        $fixture = $root.'/tests/fixtures/cas/success.xml';

        $body = <<<'PHP'
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Drivers\CasDriver;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeHttpClient;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

$success = (new Response())->withStatus(200)->withBody(Stream::create(file_get_contents(__FIXTURE__)));
$driver = new CasDriver('https://cas.test/cas', 'https://app.test/login', new FakeHttpClient([$success]), new Psr17Factory());
$sesame = new Sesame(
    $driver,
    new RecordingResolver('the-user'),
    new RecordingSession(new EventLog()),
    new Config(driver: 'cas', environment: 'testing', casBaseUrl: 'https://cas.test/cas', casServiceUrl: 'https://app.test/login'),
);
sesame($sesame);
sesame_login('/dashboard');
PHP;

        $body = str_replace('__FIXTURE__', var_export($fixture, true), $body);

        return "<?php\ndeclare(strict_types=1);\nrequire ".var_export($autoloader, true).";\n\n".$body;
    }

    /**
     * The router script for the built-in server: it boots SESAME with a
     * driver that has a logout URL, then runs the raw logout, which must send
     * the no-store and Clear-Site-Data headers.
     *
     * The script runs in the global namespace, so it must not `use` the
     * global DateTimeImmutable: that import has no effect and PHP prints a
     * warning for it, which would send output before the logout's headers.
     */
    private function logoutRouterScript(): string
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
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
    new Config(driver: 'fake', environment: 'local', fakeUsername: 'jdoe'),
);
sesame($sesame);

sesame_logout('http://app.local/');
PHP;

        return $this->childScript($body);
    }

    /**
     * Start PHP's built-in server running $script on a free port.
     *
     * @return array{0: resource, 1: int, 2: array<int, resource>, 3: string}
     */
    private function startServer(string $script): array
    {
        $file = tempnam(sys_get_temp_dir(), 'sesame-router-').'.php';
        file_put_contents($file, $script);

        $port = $this->freePort();

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            unlink($file);
            $this->fail('Could not start the built-in PHP server.');
        }

        $this->waitForServer($port);

        return [$process, $port, $pipes, $file];
    }

    /**
     * Stop the built-in server and remove its router script.
     *
     * @param  array<int, resource>  $pipes
     */
    private function stopServer($process, array $pipes, string $file): void
    {
        // Close the pipes without a blocking read: the server is long-running
        // and never closes its ends, so stream_get_contents would hang.
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        proc_terminate($process);
        proc_close($process);
        @unlink($file);
    }

    /**
     * A free TCP port on the loopback interface.
     */
    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if (! is_resource($socket)) {
            $this->fail('Could not find a free port: '.$errstr);
        }

        $name = stream_socket_get_name($socket, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($socket);

        return $port;
    }

    /**
     * Wait until the server on $port accepts connections.
     */
    private function waitForServer(int $port): void
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

            if ($connection !== false) {
                fclose($connection);
                return;
            }

            usleep(50_000);
        }

        $this->fail('The built-in PHP server did not become ready on port '.$port.'.');
    }

    /**
     * GET $url without following redirects.
     *
     * @return array{0: int, 1: array<string, string>} The status code and the
     *         response headers, keyed by lower-cased header name.
     */
    private function httpGet(string $url): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => 5,
        ]]);

        @file_get_contents($url, false, $context);
        $rawHeaders = $http_response_header ?? [];

        $status = 0;

        if (isset($rawHeaders[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $rawHeaders[0], $match)) {
            $status = (int) $match[1];
        }

        $headers = [];

        foreach ($rawHeaders as $header) {
            if (strpos($header, ':') === false) {
                continue;
            }

            [$name, $value] = explode(':', $header, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [$status, $headers];
    }
}
