<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Session\NativeSession;

final class NativeSessionTest extends TestCase
{
    public function testInvalidateLeavesOnlyAMarkerInTheStore(): void
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
    'keys' => array_keys($_SESSION),
    'markerIsInt' => is_int($_SESSION['sesame.logged_out_at'] ?? null),
    'keptTheId' => session_id() === $oldId,
    'oldFileExists' => file_exists($savePath.'/sess_'.$oldId),
];
echo json_encode($result);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertSame(1, $result['status'], 'The session should be closed (PHP_SESSION_NONE).');
        self::assertSame(['sesame.logged_out_at'], $result['keys'], 'The store should hold only the logout marker.');
        self::assertTrue($result['markerIsInt'], 'The marker should be an int unix time.');
        self::assertTrue($result['keptTheId'], 'The session ID should be kept so the marker is findable under it.');
        self::assertTrue($result['oldFileExists'], 'The session file should still exist, holding the marker.');
    }

    public function testInvalidateWithAZeroTtlDestroysTheSessionAtOnce(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;

$savePath = sys_get_temp_dir().'/sesame-native-zero-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_TEST_ZERO');
session_start();
$_SESSION['test'] = 'value';
$oldId = session_id();

$session = new NativeSession(60, null, null, 0);
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
        self::assertFalse($result['oldFileExists'], 'With a zero window the old session file should be gone.');
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

    public function testRegenerateUnderStrictModeKeepsItsNewIdAndTheSetting(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;

$savePath = sys_get_temp_dir().'/sesame-grace-strict-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_GRACE_STRICT');
session_start();
$_SESSION['user'] = 'jdoe';
$oldId = session_id();

$session = new NativeSession(60);
$session->regenerate();

$newId = session_id();
$strictAfter = ini_get('session.use_strict_mode');
$status = session_status();
$newData = $_SESSION;
session_write_close();

// A later request on the new ID, with strict mode on, must find its data.
session_id($newId);
session_start();
$laterId = session_id();
$laterData = $_SESSION;
session_write_close();

session_id($oldId);
session_start();
$oldData = $_SESSION;
session_write_close();

echo json_encode([
    'strictAfter' => $strictAfter,
    'status' => $status,
    'newData' => $newData,
    'newFileExists' => file_exists($savePath.'/sess_'.$newId),
    'laterKeptTheNewId' => $laterId === $newId,
    'laterData' => $laterData,
    'markerNamesTheNewId' => ($oldData['sesame.new_session_id'] ?? null) === $newId,
]);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertSame('1', $result['strictAfter'], 'regenerate() must leave strict mode as the app set it.');
        self::assertSame(2, $result['status'], 'The new session should be active (PHP_SESSION_ACTIVE).');
        self::assertSame(['user' => 'jdoe'], $result['newData'], 'The new session should hold the data and neither marker.');
        self::assertTrue($result['newFileExists'], 'The new session should be in the store under its new ID.');
        self::assertTrue($result['laterKeptTheNewId'], 'Strict mode must accept the new ID on the next request.');
        self::assertSame(['user' => 'jdoe'], $result['laterData'], 'The next request on the new ID should see the data.');
        self::assertTrue($result['markerNamesTheNewId'], 'The old session marker should name the ID the user is really on.');
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

    public function testAReusedLoggedOutSessionIsLoggedOnceAndRetired(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\Tests\Support\ArrayLogger;
use Waynestate\Sesame\Tests\Support\FakeClock;

$savePath = sys_get_temp_dir().'/sesame-reuse-log-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_REUSE_LOG');
session_start();
$_SESSION['user'] = 'jdoe';
$oldId = session_id();

$clock = new FakeClock();
$logger = new ArrayLogger();
$session = new NativeSession(60, $logger, $clock);
$session->invalidate();

// A later request arrives with the dead ID, 30 seconds into the window.
$clock->advance(30);
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
        self::assertNull($result['user'], 'The reused logged-out session should be signed out.');
        self::assertSame(2, $result['status'], 'A clean session should be active (PHP_SESSION_ACTIVE).');
        self::assertTrue($result['retiredToANewId'], 'The dead ID should be retired and a new one started.');
        self::assertSame(1, $result['recordCount'], 'Exactly one anomaly record should be logged per reuse.');
        self::assertSame('sesame.session_anomaly', $result['message'], 'The record should be the anomaly event.');
        self::assertSame('warning', $result['level'], 'The record should be a warning.');
        self::assertSame([
            'event' => 'session_anomaly',
            'reason' => 'logged_out_session_reused',
            'at' => '2026-10-27T12:00:30+00:00',
        ], $result['context'], 'The context should carry the event, the reason and the time only.');
    }

    public function testAReusedLoggedOutSessionPastTheWindowIsNotLogged(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\Tests\Support\ArrayLogger;
use Waynestate\Sesame\Tests\Support\FakeClock;

$savePath = sys_get_temp_dir().'/sesame-reuse-expired-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_REUSE_EXPIRED');
session_start();
$_SESSION['user'] = 'jdoe';
$oldId = session_id();

$clock = new FakeClock();
$logger = new ArrayLogger();
$session = new NativeSession(60, $logger, $clock);
$session->invalidate();

// A later request arrives with the dead ID, 90 seconds on: past the window.
$clock->advance(90);
session_id($oldId);
session_start();
$later = new NativeSession(60, $logger, $clock);
$user = $later->get('user');

$result = [
    'user' => $user,
    'status' => session_status(),
    'retiredToANewId' => session_id() !== $oldId,
    'recordCount' => count($logger->records),
];
echo json_encode($result);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertNull($result['user'], 'The old session data is gone either way.');
        self::assertSame(2, $result['status'], 'A fresh session should be active (PHP_SESSION_ACTIVE).');
        self::assertTrue($result['retiredToANewId'], 'A marked session is always retired, even past the window.');
        self::assertSame(0, $result['recordCount'], 'No anomaly should be logged past the window.');
    }

    public function testAThrowingLoggerDoesNotChangeWhatTheCallerSees(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\ThrowingLogger;

$savePath = sys_get_temp_dir().'/sesame-reuse-throw-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_REUSE_THROW');
session_start();
$_SESSION['user'] = 'jdoe';
$oldId = session_id();

$clock = new FakeClock();
$logger = new ThrowingLogger();
$session = new NativeSession(60, $logger, $clock);
$session->invalidate();

// A later request arrives with the dead ID, inside the window.
$clock->advance(30);
session_id($oldId);
session_start();
$later = new NativeSession(60, $logger, $clock);
$user = $later->get('user');

$result = [
    'user' => $user,
    'status' => session_status(),
    'retiredToANewId' => session_id() !== $oldId,
    'logAttempts' => count($logger->messages),
];
echo json_encode($result);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertNull($result['user'], 'The reuse should still sign the caller out.');
        self::assertSame(2, $result['status'], 'A clean session should be active (PHP_SESSION_ACTIVE).');
        self::assertTrue($result['retiredToANewId'], 'The dead ID should still be retired.');
        self::assertSame(1, $result['logAttempts'], 'The anomaly should still be attempted once.');
    }

    public function testAFreshSessionIdIsUnaffectedByTheReuseCheck(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\Tests\Support\ArrayLogger;
use Waynestate\Sesame\Tests\Support\FakeClock;

$savePath = sys_get_temp_dir().'/sesame-reuse-fresh-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_REUSE_FRESH');
session_start();
$_SESSION['user'] = 'jdoe';
$id = session_id();

$clock = new FakeClock();
$logger = new ArrayLogger();
$session = new NativeSession(60, $logger, $clock);
$session->put('count', 1);
session_write_close();

// A later request arrives with the same live ID, never invalidated.
$clock->advance(30);
session_id($id);
session_start();
$later = new NativeSession(60, $logger, $clock);
$user = $later->get('user');

$result = [
    'user' => $user,
    'count' => $later->get('count'),
    'status' => session_status(),
    'keptTheId' => session_id() === $id,
    'recordCount' => count($logger->records),
];
echo json_encode($result);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertSame('jdoe', $result['user'], 'A live session must keep serving its data.');
        self::assertSame(1, $result['count'], 'A live session must keep serving its data.');
        self::assertSame(2, $result['status'], 'The session should stay active (PHP_SESSION_ACTIVE).');
        self::assertTrue($result['keptTheId'], 'A live session must not be retired by the reuse check.');
        self::assertSame(0, $result['recordCount'], 'A live session must not be logged as an anomaly.');
    }

    public function testZeroTtlDisablesTheReuseCheck(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\Tests\Support\ArrayLogger;
use Waynestate\Sesame\Tests\Support\FakeClock;

$savePath = sys_get_temp_dir().'/sesame-reuse-zero-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_REUSE_ZERO');
session_start();
$_SESSION['user'] = 'jdoe';
$oldId = session_id();

$clock = new FakeClock();
$logger = new ArrayLogger();
$session = new NativeSession(60, $logger, $clock, 0);
$session->invalidate();

// A later request arrives with the dead ID, where the window would have
// caught it.
$clock->advance(30);
session_id($oldId);
session_start();
$later = new NativeSession(60, $logger, $clock, 0);
$user = $later->get('user');

$result = [
    'user' => $user,
    'status' => session_status(),
    'keptTheOldId' => session_id() === $oldId,
    'recordCount' => count($logger->records),
];
echo json_encode($result);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertNull($result['user'], 'The old session data is gone either way.');
        self::assertSame(2, $result['status'], 'A fresh session should be active (PHP_SESSION_ACTIVE).');
        self::assertFalse($result['keptTheOldId'], 'With the check off, strict mode still refuses the dead ID: PHP starts the fresh session under a new one.');
        self::assertSame(0, $result['recordCount'], 'No anomaly should be logged with the check off.');
    }

    public function testTheSafeSettingsAreTurnedOnBeforeTheSessionStarts(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;

// PHP 8.4 deprecates use_only_cookies=0, which is the point: set it anyway.
error_reporting(E_ALL & ~E_DEPRECATED);

$savePath = sys_get_temp_dir().'/sesame-settings-auto-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_SETTINGS_AUTO');
// A shared php.ini that nobody hardened.
ini_set('session.use_strict_mode', '0');
ini_set('session.use_only_cookies', '0');

$warnings = [];
set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
}, E_USER_WARNING);

// The app builds SESAME's session first, then starts the session with an
// ID an attacker planted.
$session = new NativeSession();
$planted = 'plantedbyanattacker0123456789ab';
session_id($planted);
session_start();
$session->put('user', 'jdoe');

echo json_encode([
    'strict' => ini_get('session.use_strict_mode'),
    'onlyCookies' => ini_get('session.use_only_cookies'),
    'plantedIdRefused' => session_id() !== $planted,
    'user' => $session->get('user'),
    'warnings' => $warnings,
]);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertSame('1', $result['strict'], 'Strict mode should be turned on before the session starts.');
        self::assertSame('1', $result['onlyCookies'], 'Cookie-only IDs should be turned on before the session starts.');
        self::assertTrue($result['plantedIdRefused'], 'With strict mode on, PHP must refuse the planted ID.');
        self::assertSame('jdoe', $result['user']);
        self::assertSame([], $result['warnings'], 'Settings that were fixed in time need no warning.');
    }

    public function testAnActiveSessionWithoutTheSafeSettingsWarnsOncePerRequest(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;

$savePath = sys_get_temp_dir().'/sesame-settings-warn-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_SETTINGS_WARN');
ini_set('session.use_strict_mode', '0');
session_start();
$_SESSION['user'] = 'jdoe';

$warnings = [];
set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
}, E_USER_WARNING);

$first = new NativeSession();
$users = [$first->get('user'), $first->get('user')];
$first->put('count', 1);
$second = new NativeSession();
$users[] = $second->get('user');

echo json_encode([
    'users' => $users,
    'warnings' => $warnings,
    'strictStillOff' => ini_get('session.use_strict_mode') === '0',
]);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertSame(['jdoe', 'jdoe', 'jdoe'], $result['users'], 'The session keeps working: a warning, not a stop.');
        self::assertCount(1, $result['warnings'], 'One warning per request, however many calls and instances.');
        self::assertStringContainsString('session.use_strict_mode', $result['warnings'][0]);
        self::assertStringNotContainsString('use_only_cookies', $result['warnings'][0], 'Only the setting that is off is named.');
        self::assertStringContainsString("ini_set('session.use_strict_mode', '1');", $result['warnings'][0], 'The warning should carry the one-line fix.');
        self::assertTrue($result['strictStillOff'], 'An active session cannot change its settings, so they are left as they are.');
    }

    public function testCheckSessionSettingsTrueRefusesOnEveryCall(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;

// PHP 8.4 deprecates use_only_cookies=0, which is the point: set it anyway.
error_reporting(E_ALL & ~E_DEPRECATED);

$savePath = sys_get_temp_dir().'/sesame-settings-strict-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_SETTINGS_STRICT');
ini_set('session.use_strict_mode', '0');
ini_set('session.use_only_cookies', '0');
session_start();
$_SESSION['user'] = 'jdoe';

$session = new NativeSession(checkSessionSettings: true);
$messages = [];
foreach (['get', 'put', 'get'] as $call) {
    try {
        $call === 'get' ? $session->get('user') : $session->put('user', 'mallory');
        $messages[] = null;
    } catch (LogicException $e) {
        $messages[] = $e->getMessage();
    }
}

echo json_encode(['messages' => $messages, 'user' => $_SESSION['user']]);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertCount(3, $result['messages']);

        foreach ($result['messages'] as $message) {
            self::assertIsString($message, 'With checkSessionSettings: true every call is refused, not only the first.');
            self::assertStringContainsString('session.use_strict_mode and session.use_only_cookies', $message);
        }

        self::assertSame('jdoe', $result['user'], 'A refused put() must not write to the session.');
    }

    public function testSettingsSpelledOnPassAndFalseTurnsEverythingOff(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;

$savePath = sys_get_temp_dir().'/sesame-settings-off-'.getmypid();
if (! is_dir($savePath)) {
    mkdir($savePath);
}
session_save_path($savePath);
session_name('SESAME_SETTINGS_OFF');

$warnings = [];
set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
}, E_USER_WARNING);

ini_set('session.use_strict_mode', 'On');
ini_set('session.use_only_cookies', 'yes');
session_start();
$_SESSION['user'] = 'jdoe';
$spelledOn = (new NativeSession(checkSessionSettings: true))->get('user');
session_write_close();

ini_set('session.use_strict_mode', '0');
$off = new NativeSession(checkSessionSettings: false);
$strictAfterConstruct = ini_get('session.use_strict_mode');
session_start();
$checkOff = $off->get('user');

echo json_encode([
    'spelledOn' => $spelledOn,
    'checkOff' => $checkOff,
    'strictAfterConstruct' => $strictAfterConstruct,
    'warnings' => $warnings,
]);
PHP;

        $output = $this->runChildPhp($this->childScript($body));
        $result = json_decode($output, true);

        self::assertIsArray($result, 'Child output was not JSON: ' . $output);
        self::assertSame('jdoe', $result['spelledOn'], 'On, yes and 1 all count as on.');
        self::assertSame('jdoe', $result['checkOff'], 'checkSessionSettings: false neither throws nor stops the session.');
        self::assertSame('0', $result['strictAfterConstruct'], 'checkSessionSettings: false leaves the settings alone.');
        self::assertSame([], $result['warnings'], 'checkSessionSettings: false raises no warning.');
    }

    /**
     * Wrap a snippet in a runnable script that loads the autoloader first.
     *
     * Every child runs with strict mode on, as production does (HELMET and
     * Squire's php.ini turn it on), so the tests take the path real apps
     * take. A test that needs it off says so in its own body.
     */
    private function childScript(string $body): string
    {
        $autoloader = dirname(__DIR__) . '/vendor/autoload.php';

        return "<?php\ndeclare(strict_types=1);\nrequire " . var_export($autoloader, true) . ";\n"
            . "ini_set('session.use_strict_mode', '1');\n\n" . $body;
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
