<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Revocation\FileRevocationStore;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

/**
 * The file revocation store (Booster ADR-0024): two halves of one app share
 * a directory, not a cache. The rules are the PSR-16 store's: the later
 * cutoff wins, no plain username on disk, a TTL, and nothing ever throws.
 */
final class FileRevocationStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sesame-revoked-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->files() as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    public function testItKeepsTheLaterOfTwoCutoffsUnderAHashedName(): void
    {
        $store = new FileRevocationStore($this->dir, 3600, new FakeClock());

        self::assertNull($store->revokedBefore('jdoe'));

        $store->revokeBefore('jdoe', 100);
        $store->revokeBefore('jdoe', 90);
        self::assertSame(100, $store->revokedBefore('jdoe'), 'An earlier cutoff cannot un-revoke a later one.');

        $store->revokeBefore('jdoe', 200);
        self::assertSame(200, $store->revokedBefore('jdoe'));
        self::assertNull($store->revokedBefore('asmith'), 'One user\'s cutoff never leaks into another user\'s.');

        $files = array_map('basename', $this->files());
        self::assertSame(['sesame.revoked.' . hash('sha256', 'jdoe')], $files, 'One file per user, no temporary file left.');
        self::assertStringNotContainsString('jdoe', (string) file_get_contents($this->dir . DIRECTORY_SEPARATOR . $files[0]));
    }

    public function testTwoStoresOnOneDirectoryShareTheCutoffs(): void
    {
        $legacy = new FileRevocationStore($this->dir, 3600, new FakeClock());
        $laravel = new FileRevocationStore($this->dir . DIRECTORY_SEPARATOR, 3600, new FakeClock());

        $laravel->revokeBefore('jdoe', 150);

        self::assertSame(150, $legacy->revokedBefore('jdoe'));
    }

    public function testACutoffDiesWithItsTtl(): void
    {
        $clock = new FakeClock();
        $store = new FileRevocationStore($this->dir, 60, $clock);

        $store->revokeBefore('jdoe', 100);
        self::assertSame(100, $store->revokedBefore('jdoe'));

        $clock->advance(61);

        self::assertNull($store->revokedBefore('jdoe'), 'A forgotten cutoff dies with its TTL.');
        self::assertSame([], $this->files(), 'The expired file is removed.');
    }

    public function testABrokenFileOrAnUnwritableDirectoryNeverThrows(): void
    {
        mkdir($this->dir, 0o777, true);
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'sesame.revoked.' . hash('sha256', 'jdoe'), "not a cutoff\n");

        $store = new FileRevocationStore($this->dir, 60, new FakeClock());
        self::assertNull($store->revokedBefore('jdoe'), 'A file that does not parse counts as no cutoff.');

        // A path under a regular file can never be a directory, on every OS.
        $blocked = new FileRevocationStore($this->dir . DIRECTORY_SEPARATOR . 'sesame.revoked.' . hash('sha256', 'jdoe') . DIRECTORY_SEPARATOR . 'sub', 60, new FakeClock());
        $blocked->revokeBefore('jdoe', 100);
        self::assertNull($blocked->revokedBefore('jdoe'), 'A failed write is dropped.');
    }

    public function testALogoutInOneHalfSignsTheOtherHalfOut(): void
    {
        $clock = new FakeClock();
        $config = Config::fromArray(['driver' => 'fake', 'environment' => 'testing', 'fake' => ['username' => 'jdoe']]);
        $legacySession = new RecordingSession(new EventLog());
        $laravelSession = new RecordingSession(new EventLog());
        $legacy = new Sesame(new FakeDriver('testing', 'jdoe', []), new RecordingResolver(), $legacySession, $config, $clock, null, null, new FileRevocationStore($this->dir, 3600, $clock));
        $laravel = new Sesame(new FakeDriver('testing', 'jdoe', []), new RecordingResolver(), $laravelSession, $config, $clock, null, null, new FileRevocationStore($this->dir, 3600, $clock));

        $legacy->login(new ServerRequest('GET', '/login'));
        $clock->advance(5);
        $laravel->login(new ServerRequest('GET', '/_laravel/login'));
        self::assertNotNull($legacy->user());
        self::assertNotNull($laravel->user());

        $clock->advance(5);
        $laravel->logout('https://app.example.test/');

        self::assertNull($legacy->user(), 'The Laravel logout ended the legacy session.');
        self::assertNull($laravel->user());

        $clock->advance(5);
        $legacy->login(new ServerRequest('GET', '/login'));
        self::assertNotNull($legacy->user(), 'A sign-in after the cutoff is untouched.');
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*');

        return $files === false ? [] : $files;
    }
}
