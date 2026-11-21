<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Revocation\InMemoryRevocationStore;
use Waynestate\Sesame\RevocationStore;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\SessionPort;

/**
 * The signed-in checks, request by request, over any session store.
 *
 * Each scenario signs in, lets the session go through the store between
 * requests (SessionRoundTrip::next()), and answers with plain facts, so the
 * same scenario runs in this process over a Laravel store and in a child
 * process over PHP's own session, and the test compares the facts. Every
 * request builds its own Sesame, as a real app does.
 */
final class SessionScenarios
{
    /**
     * Run one scenario by name: the child process names it in a string.
     *
     * @param list<string> $args
     * @return array<string, mixed>
     */
    public static function run(string $name, SessionRoundTrip $trip, array $args = []): array
    {
        return match ($name) {
            'sharedLogout' => self::sharedLogout($trip),
            'logoutWritesTheCutoff' => self::logoutWritesTheCutoff($trip),
            'mfaAndContexts' => self::mfaAndContexts($trip),
            'tampered' => self::tampered($trip, $args[0] ?? ''),
            'legacyObject' => self::legacyObject($trip),
            default => throw new InvalidArgumentException('No scenario named ' . $name . '.'),
        };
    }

    /**
     * Another app sharing the revocation store signs the user out: the
     * next request here is signed out too, and stays signed out.
     *
     * @return array<string, mixed>
     */
    public static function sharedLogout(SessionRoundTrip $trip): array
    {
        $clock = new FakeClock();
        $store = new InMemoryRevocationStore();

        self::sesame($trip->next(), $clock, $store)->login(self::request());

        $clock->advance(60);
        $before = self::sesame($trip->next(), $clock, $store)->user();

        // The other app's logout, after this sign-in.
        $clock->advance(60);
        $store->revokeBefore('jdoe', $clock->now()->getTimestamp());

        $clock->advance(1);
        $session = $trip->next();
        $after = self::sesame($session, $clock, $store)->user();
        $userKey = $session->get('sesame.user');

        $clock->advance(1);
        $later = self::sesame($trip->next(), $clock, $store)->user();

        return [
            'before' => $before,
            'after' => $after,
            'userKeyAfter' => $userKey,
            'later' => $later,
        ];
    }

    /**
     * A logout here, on a later request than the sign-in, writes the shared
     * cutoff, so the other apps sharing the store sign the user out.
     *
     * @return array<string, mixed>
     */
    public static function logoutWritesTheCutoff(SessionRoundTrip $trip): array
    {
        $clock = new FakeClock();
        $store = new InMemoryRevocationStore();

        self::sesame($trip->next(), $clock, $store)->login(self::request());

        $clock->advance(60);
        $user = self::sesame($trip->next(), $clock, $store)->user();

        $clock->advance(60);
        self::sesame($trip->next(), $clock, $store)->logout('https://app.test/');

        return [
            'user' => $user,
            'cutoff' => $store->revokedBefore('jdoe'),
            'now' => $clock->now()->getTimestamp(),
        ];
    }

    /**
     * The MFA, auth-context and status checks read the identity back on a
     * later request.
     *
     * @return array<string, mixed>
     */
    public static function mfaAndContexts(SessionRoundTrip $trip): array
    {
        $clock = new FakeClock();
        $identity = self::identity($clock);

        self::sesame($trip->next(), $clock, null, null, $identity)->login(self::request());

        $clock->advance(60);
        $sesame = self::sesame($trip->next(), $clock, null, null, $identity);
        $mfa = $sesame->requireMfa(3600);
        $status = $sesame->sessionStatus();

        return [
            'mfaSatisfied' => $mfa->satisfied,
            'mfaReason' => $mfa->reason,
            'c1' => $sesame->hasAuthContext('c1'),
            'c10' => $sesame->hasAuthContext('c10'),
            'c2' => $sesame->hasAuthContext('c2'),
            'statusSignedIn' => $status['signedIn'],
            'statusUsername' => $status['username'] ?? null,
            'statusDriver' => $status['driver'] ?? null,
            'statusAuthenticatedAt' => $status['authenticatedAt'] ?? null,
            'statusAuthTime' => $status['authTime'] ?? null,
            'statusAmr' => $status['amr'] ?? null,
        ];
    }

    /**
     * The stored identity is changed between requests: the next request
     * signs the session out as bad_identity and logs the anomaly, and
     * nothing throws.
     *
     * @return array<string, mixed>
     */
    public static function tampered(SessionRoundTrip $trip, string $how): array
    {
        $clock = new FakeClock();
        $store = new InMemoryRevocationStore();

        self::sesame($trip->next(), $clock, $store)->login(self::request());

        $clock->advance(60);
        $session = $trip->next();
        $stored = $session->get('sesame.identity');
        $storedIsArray = is_array($stored);

        $changes = [
            'wrong_type' => ['authenticatedAt', 1793000000],
            'bad_date' => ['authenticatedAt', '2026-02-31T12:00:00+00:00'],
            'unknown_driver' => ['driver', 'evil'],
            'extra_field' => ['admin', true],
            'empty_username' => ['username', ''],
            'amr_not_a_list' => ['amr', 'mfa'],
        ];

        if (is_array($stored) && isset($changes[$how])) {
            $stored[$changes[$how][0]] = $changes[$how][1];
        } elseif (is_array($stored) && $how === 'missing_field') {
            unset($stored['authenticatedAt']);
        } elseif ($how === 'not_an_array') {
            $stored = 'jdoe';
        }

        $session->put('sesame.identity', $stored);

        $clock->advance(1);
        $logger = new ArrayLogger();
        $session = $trip->next();
        $sesame = self::sesame($session, $clock, $store, $logger);
        $user = $sesame->user();

        return [
            'storedIsArray' => $storedIsArray,
            'user' => $user,
            'userKeyAfter' => $session->get('sesame.user'),
            'identityKeyAfter' => $session->get('sesame.identity'),
            'anomalies' => self::reasons($logger, 'sesame.session_anomaly'),
            'logouts' => self::reasons($logger, 'sesame.logout'),
            'loggedUsernames' => self::usernames($logger),
            'cutoff' => $store->revokedBefore('jdoe'),
        ];
    }

    /**
     * A session 0.10.0 made: the Identity object itself under
     * sesame.identity. Over a store that keeps objects it still reads (the
     * user, MFA, contexts, the cutoff on logout); over a JSON store it came
     * back as an array of the object's public properties, which is not the
     * shape login() writes, so it signs out once as bad_identity.
     *
     * @return array<string, mixed>
     */
    public static function legacyObject(SessionRoundTrip $trip): array
    {
        $clock = new FakeClock();
        $store = new InMemoryRevocationStore();
        $now = $clock->now()->getTimestamp();

        $session = $trip->next();
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', self::identity($clock));
        $session->put('sesame.signed_in_at', $now);
        $session->put('sesame.last_seen', $now);
        $session->put('sesame.session_ref', '0123456789abcdef');

        $clock->advance(60);
        $logger = new ArrayLogger();
        $session = $trip->next();
        $stored = $session->get('sesame.identity');
        $sesame = self::sesame($session, $clock, $store, $logger);
        $user = $sesame->user();
        $mfa = $sesame->requireMfa(3600);
        $c1 = $sesame->hasAuthContext('c1');

        $clock->advance(60);
        self::sesame($trip->next(), $clock, $store, $logger)->logout('https://app.test/');

        return [
            'storedIsObject' => $stored instanceof Identity,
            'user' => $user,
            'mfaSatisfied' => $mfa->satisfied,
            'c1' => $c1,
            'cutoffWritten' => $store->revokedBefore('jdoe') !== null,
            'anomalies' => self::reasons($logger, 'sesame.session_anomaly'),
            'logouts' => self::reasons($logger, 'sesame.logout'),
        ];
    }

    /**
     * The identity every scenario signs in with: a CAS sign-in with an MFA
     * time and method and two authentication contexts.
     */
    private static function identity(FakeClock $clock): Identity
    {
        return new Identity(
            'jdoe',
            ['cn' => ['John Doe']],
            'cas',
            new DateTimeImmutable('2026-10-27T07:59:30-04:00'),
            $clock->now()->modify('-30 seconds'),
            ['mfa'],
            ['c1', 'c10'],
        );
    }

    private static function sesame(
        SessionPort $session,
        FakeClock $clock,
        ?RevocationStore $store = null,
        ?ArrayLogger $logger = null,
        ?Identity $identity = null,
    ): Sesame {
        return new Sesame(
            new RecordingDriver(new Authenticated($identity ?? self::identity($clock)), null, new EventLog()),
            new RecordingResolver('the-user'),
            $session,
            new Config(driver: 'cas', environment: 'testing', casBaseUrl: 'https://cas.test/cas', casServiceUrl: 'https://app.test/'),
            $clock,
            $logger,
            null,
            $store,
        );
    }

    private static function request(): ServerRequest
    {
        return new ServerRequest('GET', 'https://app.test/login');
    }

    /**
     * @return list<mixed>
     */
    private static function reasons(ArrayLogger $logger, string $message): array
    {
        return array_map(static fn(array $record): mixed => $record['context']['reason'] ?? null, $logger->withMessage($message));
    }

    /**
     * @return list<mixed>
     */
    private static function usernames(ArrayLogger $logger): array
    {
        $names = [];

        foreach (['sesame.session_anomaly', 'sesame.logout'] as $message) {
            foreach ($logger->withMessage($message) as $record) {
                $names[] = $record['context']['username'] ?? null;
            }
        }

        return $names;
    }
}
