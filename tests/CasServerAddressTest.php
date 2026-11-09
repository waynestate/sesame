<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Playbook\Playbook;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Exception\InvalidConfig;

/**
 * Pins the university's CAS server address, so the docs cannot drift back.
 *
 * SESAME has no built-in CAS address: `cas.base_url` is required config. What
 * it does have is examples people copy, and those once named a host that
 * answers 404 on /cas/login. The working server is Academica's; people still
 * see the login.wayne.edu screen, because Academica sends the browser there
 * to sign in. Wrong door, no matter how politely you say the magic words.
 */
final class CasServerAddressTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** The university's CAS server, the value every example uses. */
    private const CAS_BASE_URL = 'https://academica.aws.wayne.edu/cas';

    /** The sign-in screen's host, which is not a CAS server. */
    private const NOT_A_CAS_SERVER = 'login.wayne.edu/cas';

    /** The pages whose copyable config names the CAS server. */
    private const EXAMPLES = [
        'README.md',
        'docs/guides/driver-cas.md',
        'docs/guides/moving-off-wayne-auth.md',
    ];

    /** The shipped text a reader or an agent copies an address from. */
    private const SCANNED = ['README.md', 'MAINTAINING.md', 'BEST_PRACTICES.md', 'config', 'src', 'docs', 'site'];

    protected function setUp(): void
    {
        parent::setUp();

        Playbook::reset();
        $this->clearEnvironment();
    }

    protected function tearDown(): void
    {
        $this->clearEnvironment();
        Playbook::reset();

        parent::tearDown();
    }

    public function testEveryCopyableExampleUsesTheWorkingCasServer(): void
    {
        foreach (self::EXAMPLES as $page) {
            $text = (string) file_get_contents(self::ROOT . '/' . $page);

            self::assertStringContainsString(
                "'base_url' => '" . self::CAS_BASE_URL . "'",
                $text,
                $page . ' must show cas.base_url as ' . self::CAS_BASE_URL . ', the university CAS server.',
            );
        }
    }

    public function testNoShippedTextNamesTheSignInScreenAsTheCasServer(): void
    {
        $offenders = [];

        foreach (self::shippedFiles() as $path) {
            if (str_contains((string) file_get_contents($path), self::NOT_A_CAS_SERVER)) {
                $offenders[] = substr($path, strlen(self::ROOT) + 1);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'These files name ' . self::NOT_A_CAS_SERVER . ' as a CAS server; it answers 404 on /cas/login. '
                . 'Use ' . self::CAS_BASE_URL . ' (people still see the login.wayne.edu screen).',
        );
    }

    public function testConfigFromArrayTakesTheCasServerFromTheArrayOnly(): void
    {
        // The Wayne Auth bridge hands its array straight to Config::fromArray(),
        // so an address in the environment or Playbook is not picked up there.
        putenv('SESAME_CAS_BASE_URL=' . self::CAS_BASE_URL);
        Playbook::setValues(['SESAME_CAS_BASE_URL' => self::CAS_BASE_URL]);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('Missing required setting SESAME_CAS_BASE_URL.');

        Config::fromArray([
            'driver' => 'cas',
            'environment' => 'production',
            'cas' => ['service_url' => 'https://app.apps.wayne.edu/'],
        ]);
    }

    /**
     * @return list<string>
     */
    private static function shippedFiles(): array
    {
        $files = [];

        foreach (self::SCANNED as $entry) {
            $path = self::ROOT . '/' . $entry;

            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && in_array($file->getExtension(), ['md', 'html', 'txt', 'php', 'json'], true)) {
                    $files[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }

        return $files;
    }

    private function clearEnvironment(): void
    {
        putenv('SESAME_CAS_BASE_URL');

        unset($_ENV['SESAME_CAS_BASE_URL'], $_SERVER['SESAME_CAS_BASE_URL']);
    }
}
