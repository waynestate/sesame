<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

    private function clearEnvironment(): void
    {
        putenv(self::KEY);

        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
    }
}
