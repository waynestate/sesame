<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use PHPUnit\Framework\TestCase;

final class NativeSessionTest extends TestCase
{
    public function testInvalidateDestroysTheSession(): void
    {
        $body = <<<'PHP'
use Waynestate\Sesame\Session\NativeSession;

$savePath = sys_get_temp_dir().'/sesame-native-'.getmypid();
mkdir($savePath);
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

        $this->assertIsArray($result, 'Child output was not JSON: ' . $output);
        $this->assertSame(1, $result['status'], 'The session should be destroyed (PHP_SESSION_NONE).');
        $this->assertSame(0, $result['sessionCount'], 'The session data should be cleared.');
        $this->assertFalse($result['oldFileExists'], 'The old session file should be gone.');
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
            $this->fail('Could not start a child PHP process.');
        }

        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($file);

        return $output;
    }
}
