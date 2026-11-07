<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\RecordingDriver;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

/**
 * Keeps the PHP status answer and the Vue adapter's fixtures in step.
 *
 * The Vue package in vue/ ships the two status answers as JSON fixtures,
 * and its TypeScript types mirror them. This test builds a real answer the
 * way the status tests do and checks its key list and value types against
 * the signed-in fixture, and its signed-out answer against the signed-out
 * fixture. Change one side of the contract and one assertion fails.
 */
final class VueContractTest extends TestCase
{
    private function identity(): Identity
    {
        return new Identity(
            'jdoe',
            ['cn' => ['John Doe']],
            'fake',
            new DateTimeImmutable('2026-10-27T12:00:00+00:00'),
        );
    }

    private function config(): Config
    {
        return new Config(
            driver: 'fake',
            environment: 'testing',
            fakeUsername: 'jdoe',
        );
    }

    /**
     * One of the Vue fixtures, decoded. The name is the file name under
     * vue/tests/fixtures/, for example status-signed-in.json.
     *
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $path = dirname(__DIR__) . '/vue/tests/fixtures/' . $name;
        $json = file_get_contents($path);
        self::assertNotFalse($json, 'The Vue fixture ' . $name . ' is missing.');
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, 'The Vue fixture ' . $name . ' is not a JSON object.');

        return $decoded;
    }

    /**
     * The JSON type of one PHP value, the way json_encode would send it.
     */
    private function jsonType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => 'object',
        };
    }

    public function testTheSignedInAnswerMatchesTheVueFixtureShape(): void
    {
        $fixture = $this->fixture('status-signed-in.json');
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
        );

        $status = $sesame->sessionStatus();

        if ($status['signedIn'] !== true) {
            self::fail('The session is signed in.');
        }

        self::assertSame(
            array_keys($fixture),
            array_keys($status),
            'The signed-in key list drifted from the Vue fixture.',
        );

        foreach ($fixture as $key => $expected) {
            self::assertSame(
                $this->jsonType($expected),
                $this->jsonType($status[$key]),
                'The type of "' . $key . '" drifted from the Vue fixture.',
            );
        }
    }

    public function testTheSignedOutAnswerEqualsTheVueFixture(): void
    {
        $fixture = $this->fixture('status-signed-out.json');
        $log = new EventLog();
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            new RecordingSession($log),
            $this->config(),
        );

        self::assertSame(
            $fixture,
            $sesame->sessionStatus(),
            'The signed-out answer drifted from the Vue fixture.',
        );
    }
}
