<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Session\NativeSession;

final class NativeSessionTest extends TestCase
{
    public function testInvalidateDestroysTheSession(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;

$savePath = sys_get_temp_dir().'/sesame-native-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_TEST');
session_start();
$_SESSION['test'] = 'value';
$oldId = session_id();

$session = new NativeSession();
$session->invalidate();

$result = [
    'status' => session_status(),
    'sessionCount' => count($_SESSION),
    'oldFileExists' => file_exists($savePath.'/sess_'.$oldId),
];
echo json_encode($result);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertSame(1, $result['status'], 'The session should be destroyed (PHP_SESSION_NONE).');
        self::assertSame(0, $result['sessionCount'], 'The session data should be cleared.');
        self::assertFalse($result['oldFileExists'], 'The old session file should be gone.');
    }

    public function testRegenerateKeepsTheOldSessionDuringTheGrace(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;

$savePath = sys_get_temp_dir().'/sesame-grace-keep-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_GRACE_KEEP');
session_start();
$_SESSION['user'] = 'jdoe';
$_SESSION['count'] = 3;
$oldId = session_id();

$session = new NativeSession(60);
$session->regenerate();

$newId = session_id();
$newData = $_SESSION;

session_write_close();
session_id($oldId);
session_start();
$oldData = $_SESSION;
session_write_close();

$result = [
    'oldFileExists' => file_exists($savePath.'/sess_'.$oldId),
    'newId' => $newId,
    'idsDiffer' => $newId !== $oldId,
    'newData' => $newData,
    'oldDestroyedAtIsInt' => is_int($oldData['sesame.destroyed_at'] ?? null),
    'oldNewSessionId' => $oldData['sesame.new_session_id'] ?? null,
];
echo json_encode($result);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertTrue($result['oldFileExists'], 'The old session file should still exist during the grace.');
        self::assertTrue($result['idsDiffer'], 'The new session ID should differ from the old one.');
        self::assertSame(['user' => 'jdoe', 'count' => 3], $result['newData'], 'The new session should hold the same data and neither marker.');
        self::assertTrue($result['oldDestroyedAtIsInt'], 'The old session should carry an int destroyed-at time.');
        self::assertSame($result['newId'], $result['oldNewSessionId'], 'The old session should carry the new session ID.');
    }

    public function testTheOldSessionIsStillUsableWithinTheGrace(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\Tests\Support\FakeClock;

$savePath = sys_get_temp_dir().'/sesame-grace-usable-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_GRACE_USABLE');
session_start();
$_SESSION['user'] = 'jdoe';
$_SESSION['count'] = 3;
$oldId = session_id();

$clock = new FakeClock();
$session = new NativeSession(60, null, $clock);
$session->regenerate();
session_write_close();

// A later request arrives with the old ID, 30 seconds into the grace.
$clock->advance(30);
session_id($oldId);
session_start();
$later = new NativeSession(60, null, $clock);

$result = [
    'user' => $later->get('user'),
    'count' => $later->get('count'),
    'status' => session_status(),
];
echo json_encode($result);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertSame('jdoe', $result['user'], 'The old session should still serve its data within the grace.');
        self::assertSame(3, $result['count'], 'The old session should still serve its data within the grace.');
        self::assertSame(2, $result['status'], 'The session should stay active (PHP_SESSION_ACTIVE).');
    }

    public function testTheOldSessionIsRejectedAfterTheGraceAndLogged(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\Tests\Support\ArrayLogger;
use Waynestate\Sesame\Tests\Support\FakeClock;

$savePath = sys_get_temp_dir().'/sesame-grace-reject-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_GRACE_REJECT');
session_start();
$_SESSION['user'] = 'jdoe';
$oldId = session_id();

$clock = new FakeClock();
$logger = new ArrayLogger();
$session = new NativeSession(60, $logger, $clock);
$session->regenerate();
session_write_close();

// Age the marker past the grace, as 100 quiet seconds would.
session_id($oldId);
session_start();
$_SESSION['sesame.destroyed_at'] -= 100;
session_write_close();

// A later request arrives with the old ID, past the grace.
session_id($oldId);
session_start();
$later = new NativeSession(60, $logger, $clock);
$user = $later->get('user');

$record = $logger->records[0] ?? null;

$result = [
    'user' => $user,
    'status' => session_status(),
    'retiredToANewId' => session_id() !== $oldId,
    'recordCount' => count($logger->records),
    'message' => $record['message'] ?? null,
    'level' => $record['level'] ?? null,
    'context' => $record['context'] ?? null,
];
echo json_encode($result);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertNull($result['user'], 'The reused old session should be signed out after the grace.');
        self::assertSame(2, $result['status'], 'A clean session should be active (PHP_SESSION_ACTIVE).');
        self::assertTrue($result['retiredToANewId'], 'The old ID should be dead and a new one started.');
        self::assertSame(1, $result['recordCount'], 'Exactly one anomaly record should be logged.');
        self::assertSame('sesame.session_anomaly', $result['message'], 'The record should be the anomaly event.');
        self::assertSame('warning', $result['level'], 'The record should be a warning.');
        self::assertSame([
            'event' => 'session_anomaly',
            'reason' => 'destroyed_session_reused',
            'at' => '2026-10-27T12:00:00+00:00',
        ], $result['context'], 'The context should carry the event, the reason and the time only.');
    }

    public function testZeroGraceDeletesTheOldSessionAtOnce(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;

$savePath = sys_get_temp_dir().'/sesame-grace-zero-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_GRACE_ZERO');
session_start();
$_SESSION['user'] = 'jdoe';
$oldId = session_id();

$session = new NativeSession(0);
$session->regenerate();

$result = [
    'oldFileExists' => file_exists($savePath.'/sess_'.$oldId),
    'idsDiffer' => session_id() !== $oldId,
    'user' => $_SESSION['user'] ?? null,
];
echo json_encode($result);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertFalse($result['oldFileExists'], 'With graceSeconds 0 the old session file should be gone at once.');
        self::assertTrue($result['idsDiffer'], 'The session ID should change.');
        self::assertSame('jdoe', $result['user'], 'The data should move to the new session.');
    }

    public function testANegativeGraceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NativeSession(-1);
    }

    public function testTheAnomalyLogCarriesNoSessionIdOrData(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\Tests\Support\ArrayLogger;
use Waynestate\Sesame\Tests\Support\FakeClock;

$savePath = sys_get_temp_dir().'/sesame-grace-nodata-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_GRACE_NODATA');
session_start();
$_SESSION['user'] = 'jdoe';
$_SESSION['secret'] = 'top-secret-value';
$oldId = session_id();

$clock = new FakeClock();
$logger = new ArrayLogger();
$session = new NativeSession(60, $logger, $clock);
$session->regenerate();
session_write_close();

// Age the marker past the grace, as 100 quiet seconds would.
session_id($oldId);
session_start();
$_SESSION['sesame.destroyed_at'] -= 100;
session_write_close();

// A later request arrives with the old ID, past the grace.
session_id($oldId);
session_start();
$later = new NativeSession(60, $logger, $clock);
$later->get('user');

$result = [
    'oldId' => $oldId,
    'logJson' => json_encode($logger->records),
];
echo json_encode($result);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertIsString($result['logJson']);
        self::assertStringContainsString('destroyed_session_reused', $result['logJson'], 'The anomaly should be logged at all.');
        self::assertStringNotContainsString($result['oldId'], $result['logJson'], 'The log must not carry the session ID.');
        self::assertStringNotContainsString('top-secret-value', $result['logJson'], 'The log must not carry session data.');
        self::assertStringNotContainsString('jdoe', $result['logJson'], 'The log must not carry the username.');
    }

    /**
     * Wrap a snippet in a runnable script that loads the autoloader first.
     */
    private function childScript(string $body): string
    {
        $autoloader = dirname(__DIR__) . '/vendor/autoload.php';

        return "<?php\ndeclare(strict_types=1);\nrequire " . var_export($autoloader, true) . ";\n\n" . $body;
    }

    /**
     * Run a script in a child PHP process and return its combined output.
     */
    private function runChildPhp(string $script): string
    {
        $file = tempnam(sys_get_temp_dir(), 'sesame-child-') . '.php';
        file_put_contents($file, $script);

        $process = proc_open(
            [PHP_BINARY, $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            unlink($file);
            self::fail('Could not start a child PHP process.');
        }

        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($file);

        return $output;
    }
}
