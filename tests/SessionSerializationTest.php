<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Tests\Support\LaravelRoundTrip;
use Waynestate\Sesame\Tests\Support\SessionScenarios;

/**
 * The signed-in checks survive the session store's serializer (0.10.1).
 *
 * Sourdough's Laravel apps keep sessions as JSON ('serialization' =>
 * 'json'), and a JSON session hands an object back as an array. 0.10.0
 * stored the Identity object, so on those apps every `instanceof Identity`
 * check was false from the second request on: the shared logout check was
 * skipped, logout() wrote no cutoff, and the MFA and auth-context checks
 * read nothing. Each scenario here runs over PHP's own session (in a child
 * process, through the files handler) and over a Laravel store with JSON
 * serialization, request by request (SessionScenarios).
 */
final class SessionSerializationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function stores(): iterable
    {
        yield 'native PHP session' => ['native'];
        yield 'Laravel JSON session' => ['laravel-json'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tampering(): iterable
    {
        $hows = ['missing_field', 'wrong_type', 'bad_date', 'unknown_driver', 'extra_field', 'empty_username', 'amr_not_a_list', 'not_an_array'];

        foreach (['native', 'laravel-json'] as $store) {
            foreach ($hows as $how) {
                yield $store . ': ' . $how => [$store, $how];
            }
        }
    }

    #[DataProvider('stores')]
    public function testTheSharedLogoutCheckSignsTheSessionOutAfterACutoff(string $store): void
    {
        $facts = $this->scenario('sharedLogout', $store);

        self::assertSame('the-user', $facts['before'], 'Signed in on the request after the sign-in.');
        self::assertNull($facts['after'], 'Another app\'s logout after the sign-in ends this session (0.10.0 kept a JSON session signed in).');
        self::assertNull($facts['userKeyAfter'], 'The session is cleared, not only answered as signed out.');
        self::assertNull($facts['later'], 'And it stays signed out.');
    }

    #[DataProvider('stores')]
    public function testLogoutWritesTheSharedCutoff(string $store): void
    {
        $facts = $this->scenario('logoutWritesTheCutoff', $store);

        self::assertSame('the-user', $facts['user']);
        self::assertSame($facts['now'], $facts['cutoff'], 'A logout on a later request writes the cutoff, now (0.10.0 wrote none from a JSON session).');
    }

    #[DataProvider('stores')]
    public function testTheMfaContextAndStatusChecksStillSeeTheIdentity(string $store): void
    {
        $facts = $this->scenario('mfaAndContexts', $store);

        self::assertTrue($facts['mfaSatisfied'], 'requireMfa() reads the MFA time and method back.');
        self::assertSame('SATISFIED', $facts['mfaReason']);
        self::assertTrue($facts['c1']);
        self::assertTrue($facts['c10']);
        self::assertFalse($facts['c2'], 'A context the sign-in did not verify is still refused.');
        self::assertTrue($facts['statusSignedIn']);
        self::assertSame('jdoe', $facts['statusUsername']);
        self::assertSame('cas', $facts['statusDriver']);
        self::assertSame('2026-10-27T11:59:30+00:00', $facts['statusAuthenticatedAt'], 'The time keeps its moment through the store.');
        self::assertSame('2026-10-27T11:59:30+00:00', $facts['statusAuthTime']);
        self::assertSame(['mfa'], $facts['statusAmr']);
    }

    #[DataProvider('tampering')]
    public function testATamperedIdentitySignsOutAndLogsTheAnomaly(string $store, string $how): void
    {
        $facts = $this->scenario('tampered', $store, [$how]);

        self::assertTrue($facts['storedIsArray'], 'login() stores the identity as plain data on every store.');
        self::assertNull($facts['user'], 'A tampered identity is never partly trusted.');
        self::assertNull($facts['userKeyAfter'], 'The session is signed out, not only answered as signed out.');
        self::assertNull($facts['identityKeyAfter']);
        self::assertSame(['bad_identity'], $facts['anomalies']);
        self::assertSame(['bad_identity'], $facts['logouts']);
        self::assertSame([null, null], $facts['loggedUsernames'], 'A username from a tampered identity never reaches the log.');
        self::assertNull($facts['cutoff'], 'Only a real logout writes a cutoff.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function objectStores(): iterable
    {
        yield 'native PHP session' => ['native'];
        yield 'Laravel PHP session' => ['laravel-php'];
    }

    #[DataProvider('objectStores')]
    public function testA0100SessionHoldingTheIdentityObjectStillReads(string $store): void
    {
        $facts = $this->scenario('legacyObject', $store);

        self::assertTrue($facts['storedIsObject'], 'The store handed the 0.10.0 object back.');
        self::assertSame('the-user', $facts['user'], 'Nobody is signed out by the upgrade.');
        self::assertTrue($facts['mfaSatisfied']);
        self::assertTrue($facts['c1']);
        self::assertTrue($facts['cutoffWritten'], 'A logout from a 0.10.0 session writes the cutoff.');
        self::assertSame([], $facts['anomalies']);
        self::assertSame(['logout'], $facts['logouts']);
    }

    public function testA0100JsonSessionSignsOutOnceCleanly(): void
    {
        // 0.10.0 on a JSON session stored an object that came back as an
        // array of its public properties (the private authContexts were
        // lost on the way): never the shape login() writes, so it is signed
        // out once, logged, and never throws. The next sign-in stores the
        // array form.
        $facts = $this->scenario('legacyObject', 'laravel-json');

        self::assertFalse($facts['storedIsObject']);
        self::assertNull($facts['user']);
        self::assertSame(['bad_identity'], $facts['anomalies']);
        self::assertSame(['bad_identity'], $facts['logouts']);
    }

    /**
     * Run one scenario over one store and return its facts as JSON data,
     * the same shape for every store.
     *
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function scenario(string $name, string $store, array $args = []): array
    {
        if ($store === 'native') {
            return $this->native($name, $args);
        }

        $facts = SessionScenarios::run($name, new LaravelRoundTrip($store === 'laravel-php' ? 'php' : 'json'), $args);
        $decoded = json_decode(json_encode($facts, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Run one scenario over PHP's own session in a child process, where
     * session_start() can send its cookie.
     *
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function native(string $name, array $args): array
    {
        $autoloader = dirname(__DIR__) . '/vendor/autoload.php';
        $script = "<?php\ndeclare(strict_types=1);\nrequire " . var_export($autoloader, true) . ";\n"
            . "ini_set('session.use_strict_mode', '1');\n"
            . '$name = ' . var_export($name, true) . ";\n"
            . '$args = ' . var_export($args, true) . ";\n"
            . <<<'PHP'
use Waynestate\Sesame\Tests\Support\NativeRoundTrip;
use Waynestate\Sesame\Tests\Support\SessionScenarios;

$savePath = sys_get_temp_dir().'/sesame-roundtrip-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_ROUNDTRIP');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);

$facts = SessionScenarios::run($name, new NativeRoundTrip(), $args);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
foreach (glob($savePath.'/sess_*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($savePath);

echo json_encode($facts);
PHP;

        $file = tempnam(sys_get_temp_dir(), 'sesame-roundtrip-') . '.php';
        file_put_contents($file, $script);

        $process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            unlink($file);
            self::fail('Could not start a child PHP process.');
        }

        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($file);

        $facts = json_decode($output, true);
        self::assertIsArray($facts, 'Child output was not JSON: ' . $output);

        /** @var array<string, mixed> $facts */
        return $facts;
    }
}
