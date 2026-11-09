<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A legacy Wayne Auth app signs in through the bridge, end to end.
 *
 * The fixture app (tests/fixtures/wayne-auth-app) is a raw PHP page written
 * the way Wayne Auth apps are, with its one line swapped for its SESAME
 * config. It runs under PHP's built-in web server, so the real headers,
 * cookies, session files and exits are exercised, and the test plays the
 * browser and the CAS server: it follows the redirect to CAS by hand and
 * comes back with a ticket that SESAME's own CAS fixtures answer. Nothing
 * leaves 127.0.0.1.
 */
final class WayneAuthLegacyAppTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    private string $sessions = '';

    private string $log = '';

    protected function setUp(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');

        if ($probe === false) {
            self::markTestSkipped('Cannot open a local port for the built-in server.');
        }

        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $this->sessions = sys_get_temp_dir() . '/sesame-wayne-auth-' . bin2hex(random_bytes(4));
        mkdir($this->sessions);
        $this->log = $this->sessions . '/server.log';

        $server = proc_open(
            [
                PHP_BINARY,
                '-d', 'session.save_path=' . $this->sessions,
                '-d', 'session.use_strict_mode=1',
                '-d', 'display_errors=0',
                '-d', 'log_errors=1',
                '-S', '127.0.0.1:' . $this->port,
                '-t', __DIR__ . '/fixtures/wayne-auth-app/public',
            ],
            [0 => ['pipe', 'r'], 1 => ['file', $this->log, 'a'], 2 => ['file', $this->log, 'a']],
            $pipes,
        );

        if ($server === false) {
            throw new RuntimeException('Could not start the built-in server.');
        }

        $this->server = $server;

        for ($try = 0; $try < 100; $try++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        self::fail('The built-in server did not start: ' . (string) @file_get_contents($this->log));
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }

        foreach ((array) glob($this->sessions . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->sessions);
    }

    /**
     * One plain HTTP/1.0 GET against the fixture app.
     *
     * @param list<string> $headers Extra request header lines.
     * @return array{status: int, location: string|null, cookie: string|null, type: string|null, body: string}
     */
    private function get(string $path, ?string $cookie = null, array $headers = []): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 5.0);

        if ($socket === false) {
            throw new RuntimeException('Could not reach the built-in server: ' . $error);
        }

        fwrite($socket, "GET {$path} HTTP/1.0\r\nHost: 127.0.0.1:{$this->port}\r\n"
            . ($cookie === null ? '' : "Cookie: {$cookie}\r\n")
            . implode('', array_map(static fn(string $line): string => $line . "\r\n", $headers))
            . "Connection: close\r\n\r\n");
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $head);
        preg_match('/^HTTP\/\S+ (\d{3})/', $lines[0], $status);

        $location = null;
        $setCookie = null;
        $type = null;

        foreach (array_slice($lines, 1) as $line) {
            [$header, $value] = array_pad(explode(':', $line, 2), 2, '');
            $header = strtolower(trim($header));

            if ($header === 'location') {
                $location = trim($value);
            }

            if ($header === 'content-type') {
                $type = trim($value);
            }

            if ($header === 'set-cookie') {
                // The last cookie wins, as in a browser: a regenerated
                // session sends the new ID after the old one.
                $setCookie = explode(';', trim($value), 2)[0];
            }
        }

        return ['status' => (int) ($status[1] ?? 0), 'location' => $location, 'cookie' => $setCookie, 'type' => $type, 'body' => $body];
    }

    /**
     * Sign in the whole way (guest, CAS, ticket) and return the session
     * cookie the browser holds afterwards.
     */
    private function signIn(): string
    {
        $first = $this->get('/');
        $back = $this->get('/?ticket=ST-1-fixture', $first['cookie']);
        $cookie = (string) $back['cookie'];

        self::assertSame('Signed in as ab1234', $this->get('/', $cookie)['body'], $this->serverLog());

        return $cookie;
    }

    /**
     * Move one of SESAME's session clocks back in the stored session file,
     * which is what the passing hours do to it.
     */
    private function age(string $cookie, string $key, int $seconds): void
    {
        $file = $this->sessions . '/sess_' . explode('=', $cookie, 2)[1];
        $data = (string) file_get_contents($file);
        $aged = (string) preg_replace_callback(
            '/' . preg_quote($key, '/') . '\|i:(\d+);/',
            static fn(array $m): string => $key . '|i:' . ((int) $m[1] - $seconds) . ';',
            $data,
            1,
            $count,
        );

        self::assertSame(1, $count, 'The session file holds ' . $key . ': ' . $data);
        file_put_contents($file, $aged);
    }

    /**
     * After the timeout the page goes through CAS again, under a fresh
     * session, and the sign-in completes. In 0.7.0 this was a blank 500:
     * the sign-out closed the session, and the CAS redirect's write threw.
     */
    private function assertBackThroughCas(string $oldCookie): void
    {
        $again = $this->get('/', $oldCookie);

        self::assertSame(302, $again['status'], 'Expected the CAS redirect, not a 500. ' . $this->serverLog());
        self::assertSame('https://cas.test/cas/login?service=' . rawurlencode('https://app.test/'), $again['location']);
        self::assertNotNull($again['cookie'], 'A fresh session replaces the timed-out one.');
        self::assertNotSame($oldCookie, $again['cookie']);

        $back = $this->get('/?ticket=ST-1-fixture', $again['cookie']);

        self::assertSame(302, $back['status'], 'The fresh session holds the pending sign-in. ' . $this->serverLog());
        self::assertSame('Signed in as ab1234', $this->get('/', $back['cookie'])['body']);

        $stale = $this->get('/', $oldCookie);

        self::assertSame(302, $stale['status'], 'The timed-out ID never signs anyone in again.');
        self::assertStringStartsWith('https://cas.test/cas/login?', (string) $stale['location']);
    }

    public function testAnIdleSessionGoesBackThroughCasInsteadOfA500(): void
    {
        $cookie = $this->signIn();
        $this->age($cookie, 'sesame.last_seen', 3 * 3600);

        $this->assertBackThroughCas($cookie);
    }

    public function testASessionPastItsAbsoluteLimitGoesBackThroughCas(): void
    {
        $cookie = $this->signIn();
        $this->age($cookie, 'sesame.signed_in_at', 13 * 3600);

        $this->assertBackThroughCas($cookie);
    }

    public function testAnXhrSaveAfterTheTimeoutGetsA401WithJsonNotA500(): void
    {
        $cookie = $this->signIn();
        $this->age($cookie, 'sesame.last_seen', 3 * 3600);

        $save = $this->get('/', $cookie, ['X-Requested-With: XMLHttpRequest', 'Accept: */*']);

        self::assertSame(401, $save['status'], $this->serverLog());
        self::assertSame('{"signedIn":false}', $save['body']);
        self::assertStringStartsWith('application/json', (string) $save['type']);
        self::assertNull($save['location'], 'A script gets no redirect to CAS.');

        // The next page load goes through CAS as usual.
        $page = $this->get('/', $save['cookie'] ?? $cookie);

        self::assertSame(302, $page['status']);
        self::assertStringStartsWith('https://cas.test/cas/login?', (string) $page['location']);
    }

    public function testALegacyPageSignsInThroughCasWithItsOldThreeCalls(): void
    {
        // 1. A guest opens the page: the bridge sends the browser to CAS,
        //    not to the old SSO login page, and starts a session.
        $first = $this->get('/');

        self::assertSame(302, $first['status'], $this->serverLog());
        self::assertSame('https://cas.test/cas/login?service=' . rawurlencode('https://app.test/'), $first['location']);
        self::assertNotNull($first['cookie'], 'The session that binds the sign-in to this browser.');

        // 2. CAS sends the browser back with a ticket: SESAME validates it,
        //    stores the user, and sends the browser on without the ticket.
        $back = $this->get('/?ticket=ST-1-fixture', $first['cookie']);

        self::assertSame(302, $back['status'], $this->serverLog());
        self::assertSame('https://app.test/', $back['location']);
        self::assertNotNull($back['cookie'], 'The session ID is regenerated at sign-in.');
        self::assertNotSame($first['cookie'], $back['cookie']);

        // 3. The page again: authenticateSSO() is true, getAccessID() is the
        //    CAS username, and the app's own authorized_ids check passes.
        $page = $this->get('/', $back['cookie']);

        self::assertSame(200, $page['status'], $this->serverLog());
        self::assertSame('Signed in as ab1234', $page['body']);
    }

    public function testATicketThisBrowserNeverAskedForSignsNobodyIn(): void
    {
        $forged = $this->get('/?ticket=ST-1-fixture');

        self::assertSame(500, $forged['status'], 'AuthenticationFailed stops the page.');
        self::assertStringNotContainsString('Signed in as', $forged['body']);

        $page = $this->get('/', $forged['cookie']);

        self::assertSame(302, $page['status']);
        self::assertStringStartsWith('https://cas.test/cas/login?', (string) $page['location']);
    }

    private function serverLog(): string
    {
        return 'Server log: ' . (string) @file_get_contents($this->log);
    }
}
