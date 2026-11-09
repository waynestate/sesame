<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Drivers\Saml\SamlOptions;
use Waynestate\Sesame\Drivers\SamlDriver;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Tests\Support\ArrayCache;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeSamlIdp;
use Waynestate\Sesame\Tests\Support\RecordingSession;

/**
 * The saml driver's config (SamlOptions), its DriverFactory arm, and the
 * security floor for php-saml and xmlseclibs.
 */
final class SamlConfigTest extends TestCase
{
    private static ?string $metadataFile = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$metadataFile !== null) {
            @unlink(self::$metadataFile);
        }
    }

    public function testTheInstalledLibrariesMeetTheFloor(): void
    {
        // The security floor: fails if the installed php-saml or
        // xmlseclibs is older than the floor the driver enforces at boot.
        foreach ([SamlDriver::LIBRARY_PACKAGE => SamlDriver::LIBRARY_FLOOR, SamlDriver::XMLSECLIBS_PACKAGE => SamlDriver::XMLSECLIBS_FLOOR] as $package => $floor) {
            self::assertTrue(InstalledVersions::isInstalled($package), $package . ' is not installed.');
            $version = (string) InstalledVersions::getPrettyVersion($package);
            self::assertTrue(SamlDriver::meetsFloor($version, $floor), sprintf('%s %s is below the floor %s.', $package, $version, $floor));
        }

        SamlDriver::assertLibraryInstalled();
    }

    public function testComposerJsonHoldsTheSameFloor(): void
    {
        /** @var array{conflict: array<string, string>, suggest: array<string, string>, "require-dev": array<string, string>} $composer */
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('<' . SamlDriver::LIBRARY_FLOOR, $composer['conflict'][SamlDriver::LIBRARY_PACKAGE]);
        self::assertSame('<' . SamlDriver::XMLSECLIBS_FLOOR, $composer['conflict'][SamlDriver::XMLSECLIBS_PACKAGE]);
        self::assertSame('^' . SamlDriver::LIBRARY_FLOOR, $composer['require-dev'][SamlDriver::LIBRARY_PACKAGE]);
        self::assertStringContainsString('^' . SamlDriver::LIBRARY_FLOOR, $composer['suggest'][SamlDriver::LIBRARY_PACKAGE]);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function versions(): iterable
    {
        yield 'the floor' => ['4.3.2', '4.3.2', true];
        yield 'above' => ['4.4.1', '4.3.2', true];
        yield 'a v prefix' => ['v4.3.2', '4.3.2', true];
        yield 'below' => ['4.3.1', '4.3.2', false];
        yield 'the old major' => ['3.7.0', '4.3.2', false];
        yield 'a dev branch' => ['dev-master', '4.3.2', false];
        yield 'xmlseclibs below' => ['3.1.4', '3.1.5', false];
    }

    #[DataProvider('versions')]
    public function testMeetsFloor(string $version, string $floor, bool $meets): void
    {
        self::assertSame($meets, SamlDriver::meetsFloor($version, $floor));
    }

    public function testAMissingLibraryGivesAnInstallHint(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('composer require onelogin/php-saml');

        SamlDriver::assertLibraryInstalled('OneLogin\\Saml2\\NoSuchClass');
    }

    public function testTheDefaults(): void
    {
        $options = SamlOptions::fromArray(self::config());

        self::assertFalse($options->signAuthnRequests);
        self::assertFalse($options->wantMessagesSigned);
        self::assertSame(SamlOptions::DEFAULT_ATTRIBUTES, $options->attributes);
        self::assertSame('accessid', $options->usernameAttribute);
        self::assertSame(SamlOptions::DEFAULT_MFA_CONTEXTS, $options->mfaContexts);
        self::assertSame(60, $options->leewaySeconds);
        self::assertSame(300, $options->maxAssertionAgeSeconds);
        self::assertNull($options->spKey());
    }

    public function testEnvironmentShapedValuesAreAccepted(): void
    {
        $options = SamlOptions::fromArray(self::config([
            'sign_authn_requests' => '1',
            'sp' => ['cert_file' => FakeSamlIdp::fixture('sp-test-only.crt'), 'key_file' => FakeSamlIdp::fixture('sp-test-only.key')],
            'attributes' => ['accessid' => 'uid, urn:oid:0.9.2342.19200300.100.1.1', 'email' => null, 'name' => ''],
            'mfa_contexts' => '',
            'authn_context' => 'https://refeds.org/profile/mfa',
            'leeway_seconds' => '30',
        ]));

        self::assertTrue($options->signAuthnRequests);
        self::assertSame(['uid', 'urn:oid:0.9.2342.19200300.100.1.1'], $options->attributes['accessid']);
        self::assertSame(SamlOptions::DEFAULT_ATTRIBUTES['email'], $options->attributes['email']);
        self::assertSame(SamlOptions::DEFAULT_MFA_CONTEXTS, $options->mfaContexts);
        self::assertSame(['https://refeds.org/profile/mfa'], $options->authnContexts);
        self::assertSame(30, $options->leewaySeconds);
        self::assertStringContainsString('PRIVATE KEY', (string) $options->spKey());
        self::assertStringNotContainsString('PRIVATE KEY', print_r($options, true));
        self::assertStringNotContainsString('PRIVATE KEY', var_export($options, true));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badConfigs(): iterable
    {
        yield 'no entity id' => [['sp' => ['entity_id' => null]], 'SESAME_SAML_SP_ENTITY_ID'];
        yield 'no acs url' => [['sp' => ['acs_url' => '']], 'SESAME_SAML_SP_ACS_URL'];
        yield 'no service url' => [['service_url' => null], 'SESAME_SAML_SERVICE_URL'];
        yield 'an http acs url' => [['sp' => ['acs_url' => 'http://app.example.test/acs']], 'saml.sp.acs_url'];
        yield 'an acs url with a query' => [['sp' => ['acs_url' => 'https://app.example.test/acs?x=1']], 'saml.sp.acs_url'];
        yield 'no metadata' => [['idp' => ['metadata_file' => null]], 'Set exactly one'];
        yield 'both metadata sources' => [['idp' => ['metadata_url' => 'https://idp.example.test/md']], 'Set exactly one'];
        yield 'an http metadata url' => [['idp' => ['metadata_file' => null, 'metadata_url' => 'http://idp.example.test/md']], 'saml.idp.metadata_url'];
        yield 'a missing metadata file' => [['idp' => ['metadata_file' => '/nonexistent/md.xml']], 'saml.idp.metadata_file'];
        yield 'a cert without a key' => [['sp' => ['cert_file' => FakeSamlIdp::fixture('sp-test-only.crt')]], 'or neither'];
        yield 'a key that does not match' => [['sp' => ['cert_file' => FakeSamlIdp::fixture('sp-test-only.crt'), 'key_file' => FakeSamlIdp::fixture('idp-test-only.key')]], 'does not match'];
        yield 'a cert that is not one' => [['idp' => ['cert_file' => FakeSamlIdp::fixture('idp-test-only.key')]], 'saml.idp.cert_file'];
        yield 'signing without a key' => [['sign_authn_requests' => true], 'needs the SP key pair'];
        yield 'an unknown username attribute' => [['username_attribute' => 'upn'], 'saml.username_attribute'];
        yield 'a leeway beyond php-saml' => [['leeway_seconds' => 600], 'saml.leeway_seconds'];
        yield 'a non-boolean flag' => [['want_messages_signed' => 'yes'], 'saml.want_messages_signed'];
        yield 'an entity id with a space' => [['idp' => ['entity_id' => 'https://idp example']], 'saml.idp.entity_id'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('badConfigs')]
    public function testABadConfigStopsTheApp(array $overrides, string $message): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage($message);

        SamlOptions::fromArray(self::config($overrides));
    }

    public function testConfigReadsTheSamlBlockOnlyForTheSamlDriver(): void
    {
        $config = Config::fromArray(['driver' => 'saml', 'environment' => 'testing', 'saml' => self::config()]);
        self::assertInstanceOf(SamlOptions::class, $config->saml);

        $fake = Config::fromArray(['driver' => 'fake', 'environment' => 'testing', 'fake' => ['username' => 'x'], 'saml' => 'ignored']);
        self::assertNull($fake->saml);
    }

    public function testDriverFactoryBuildsTheSamlDriver(): void
    {
        $config = Config::fromArray(['driver' => 'saml', 'environment' => 'testing', 'saml' => self::config()]);
        $session = new RecordingSession(new EventLog());

        $driver = DriverFactory::make($config, null, null, null, null, new ArrayCache(), $session);

        self::assertInstanceOf(SamlDriver::class, $driver);
    }

    public function testDriverFactoryNeedsTheSessionAndTheCache(): void
    {
        $config = Config::fromArray(['driver' => 'saml', 'environment' => 'testing', 'saml' => self::config()]);

        try {
            DriverFactory::make($config, null, null, null, null, new ArrayCache());
            self::fail('The saml driver was built without a session.');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString('app session', $e->getMessage());
        }

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('PSR-16 cache');

        DriverFactory::make($config, null, null, null, null, null, new RecordingSession(new EventLog()));
    }

    public function testAMetadataUrlNeedsAnHttpClient(): void
    {
        $config = Config::fromArray(['driver' => 'saml', 'environment' => 'testing', 'saml' => self::config(['idp' => ['metadata_file' => null, 'metadata_url' => 'https://idp.example.test/md']])]);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('PSR-18 client');

        DriverFactory::make($config, null, null, null, null, new ArrayCache(), new RecordingSession(new EventLog()));
    }

    /**
     * A valid saml block, with $overrides merged in one level deep.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function config(array $overrides = []): array
    {
        self::$metadataFile ??= FakeSamlIdp::metadataFile();

        $config = [
            'sp' => ['entity_id' => FakeSamlIdp::SP_ENTITY_ID, 'acs_url' => FakeSamlIdp::ACS_URL],
            'idp' => ['metadata_file' => self::$metadataFile],
            'service_url' => FakeSamlIdp::SERVICE_URL,
        ];

        foreach ($overrides as $key => $value) {
            $config[$key] = is_array($value) && is_array($config[$key] ?? null) ? array_merge($config[$key], $value) : $value;
        }

        return $config;
    }
}
