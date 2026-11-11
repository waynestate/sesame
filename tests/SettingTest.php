<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Waynestate\Playbook\Playbook;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Support\Setting;

/**
 * The one lookup for the shared WSU values.
 *
 * The environment beats waynestate/playbook, the playbook supplies the value
 * when the environment is empty or missing, and a setting that neither has
 * stays fatal, naming the setting and pointing at the playbook.
 */
final class SettingTest extends TestCase
{
    private const KEY = 'SESAME_CAS_BASE_URL';

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

    public function testTheEnvironmentBeatsPlaybook(): void
    {
        putenv(self::KEY . '=https://cas.example.test/from-environment');
        Playbook::setValues([self::KEY => 'https://cas.example.test/from-playbook']);

        self::assertSame('https://cas.example.test/from-environment', Setting::get(self::KEY));
    }

    public function testTheEnvSuperGlobalBeatsPlaybook(): void
    {
        $_ENV[self::KEY] = 'https://cas.example.test/from-env-super';
        Playbook::setValues([self::KEY => 'https://cas.example.test/from-playbook']);

        self::assertSame('https://cas.example.test/from-env-super', Setting::get(self::KEY));
    }

    public function testTheServerSuperGlobalBeatsPlaybook(): void
    {
        $_SERVER[self::KEY] = 'https://cas.example.test/from-server-super';
        Playbook::setValues([self::KEY => 'https://cas.example.test/from-playbook']);

        self::assertSame('https://cas.example.test/from-server-super', Setting::get(self::KEY));
    }

    public function testPlaybookSuppliesTheValueWhenTheEnvironmentIsEmpty(): void
    {
        Playbook::setValues([self::KEY => 'https://cas.example.test/from-playbook']);

        self::assertSame('https://cas.example.test/from-playbook', Setting::get(self::KEY));
    }

    public function testAnEmptyEnvironmentVariableFallsThroughToPlaybook(): void
    {
        putenv(self::KEY . '=');
        Playbook::setValues([self::KEY => 'https://cas.example.test/from-playbook']);

        self::assertSame('https://cas.example.test/from-playbook', Setting::get(self::KEY));
    }

    public function testNullWhenNeitherTheEnvironmentNorPlaybookHasTheValue(): void
    {
        self::assertNull(Setting::get(self::KEY));
    }

    public function testAnEmptyPlaybookValueCountsAsMissing(): void
    {
        Playbook::setValues([self::KEY => '']);

        self::assertNull(Setting::get(self::KEY));
    }

    public function testSecretNeverComesFromPlaybook(): void
    {
        // The oidc client secret sits in ENVIRONMENT_ONLY: even a playbook
        // that knows it stays quiet, so the app's environment is the only door.
        putenv('SESAME_OIDC_CLIENT_SECRET');

        unset($_ENV['SESAME_OIDC_CLIENT_SECRET'], $_SERVER['SESAME_OIDC_CLIENT_SECRET']);
        Playbook::setValues(['SESAME_OIDC_CLIENT_SECRET' => 'not-a-real-secret']);

        self::assertNull(Setting::get('SESAME_OIDC_CLIENT_SECRET'));
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('missingRoutedSettings')]
    public function testAMissingRoutedSettingNamesTheSettingAndPointsAtPlaybook(array $config, string $message): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage($message);

        Config::fromArray($config);
    }

    /**
     * A routed setting that neither the environment nor the playbook
     * provides, and the error that must name it.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function missingRoutedSettings(): array
    {
        $hint = 'Set it in the environment, or install waynestate/playbook (see the README).';

        return [
            'the cas base url' => [
                [
                    'driver' => 'cas',
                    'environment' => 'local',
                    'cas' => ['service_url' => 'https://app.example.test/login'],
                ],
                'Missing required setting SESAME_CAS_BASE_URL. ' . $hint,
            ],
            'the gateway issuer' => [
                [
                    'driver' => 'gateway',
                    'environment' => 'local',
                    'gateway' => [
                        'public_key' => 'public-key',
                        'audience' => 'sesame',
                        'trusted_proxies' => ['10.0.0.0/8'],
                    ],
                ],
                'Missing required setting SESAME_GATEWAY_ISSUER. ' . $hint,
            ],
            'the oauth2 issuer' => [
                [
                    'driver' => 'oauth2',
                    'environment' => 'local',
                    'oauth2' => [
                        'jwks_url' => 'https://login.example.test/discovery/v2.0/keys',
                        'audience' => 'api://test-client',
                    ],
                ],
                'Missing required setting SESAME_OAUTH2_ISSUER. ' . $hint,
            ],
            'the oauth2 jwks url' => [
                [
                    'driver' => 'oauth2',
                    'environment' => 'local',
                    'oauth2' => [
                        'issuer' => 'https://login.example.test/v2.0',
                        'audience' => 'api://test-client',
                    ],
                ],
                'Missing required setting SESAME_OAUTH2_JWKS_URL. ' . $hint,
            ],
        ];
    }

    public function testSettingIsGeneratedFromPlaybook(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/src/Support/Setting.php');
        self::assertIsString($content);

        $header = '// Generated by Playbook bin/setting-sync.php. Do not edit; run it again (php bin/setting-sync.php --write ...).';
        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n\n" . $header, $content);
        self::assertStringContainsString('namespace Waynestate\Sesame\Support;', $content);

        $raw = file_get_contents(dirname(__DIR__) . '/setting-sync.json');
        self::assertIsString($raw);
        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(['SESAME_OIDC_CLIENT_SECRET'], $manifest['environment_only']);
    }

    public function testSettingCheckIsClean(): void
    {
        $process = new Process(['php', 'bin/setting-sync.php', '--check'], dirname(__DIR__));
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testHandEditIsDrift(): void
    {
        $root = dirname(__DIR__);
        $temp = sys_get_temp_dir() . '/sesame-setting-drift-' . uniqid('', true);

        try {
            foreach (['bin/setting-sync.php', 'src/Support/Setting.php', 'docs/guides/wsu-values.md', 'setting-sync.json'] as $path) {
                $target = $temp . '/' . $path;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o775, true) && !is_dir(dirname($target))) {
                    self::fail("cannot create " . dirname($target));
                }

                copy($root . '/' . $path, $target);
            }

            // A hand edit is drift, even a harmless comment.
            $edited = $temp . '/src/Support/Setting.php';
            $current = file_get_contents($edited);
            self::assertIsString($current);
            file_put_contents($edited, $current . "// A hand edit.\n");

            $process = new Process(['php', 'bin/setting-sync.php', '--check', '--root=' . $temp], $root);
            $process->run();

            self::assertSame(1, $process->getExitCode());
        } finally {
            $this->removeDirectory($temp);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    private function clearEnvironment(): void
    {
        putenv(self::KEY);

        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
    }
}
