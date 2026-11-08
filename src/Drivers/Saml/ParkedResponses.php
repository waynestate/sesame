<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Saml;

use Psr\SimpleCache\CacheInterface;

/**
 * SAML responses that have arrived at the ACS and wait for the browser to
 * come back with its session cookie.
 *
 * Why the wait: the IdP returns the response as a cross-site POST, and a
 * browser sends no SameSite=Lax (or Strict) cookie on a cross-site POST, so
 * the app's session is not there to say which sign-in this browser started
 * (S35, S41). Starting a session on that request would even overwrite the
 * real session cookie. So the ACS parks the raw response here, under a
 * random handle, without touching the session, and answers 303 to the
 * login URL with the handle. That GET is a top-level navigation, so the Lax
 * session cookie comes along, and the driver checks the response there.
 *
 * Nothing parked here is trusted: it is the raw, unchecked message. A handle
 * is 256 random bits, lives PARK_SECONDS, and is single use (take() deletes
 * it). PSR-16 has no atomic take, so two requests racing with one handle
 * could both read it; the pending request in the session (single use) and
 * the replay guard (single use per assertion ID) each stop the second one.
 */
final readonly class ParkedResponses
{
    /**
     * How long a parked response waits, in seconds: the browser comes
     * straight back, so two minutes is plenty.
     */
    public const PARK_SECONDS = 120;

    private const KEY_PREFIX = 'sesame.saml.parked.';

    public function __construct(
        private CacheInterface $cache,
    ) {}

    /**
     * Park a response and return its handle.
     */
    public function park(#[\SensitiveParameter] string $samlResponse, ?string $relayState): string
    {
        $handle = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->cache->set(self::key($handle), [
            'response' => $samlResponse,
            'relay_state' => $relayState,
        ], self::PARK_SECONDS);

        return $handle;
    }

    /**
     * Remove and return the parked response for this handle, or null when
     * there is none (unknown, used or older than PARK_SECONDS).
     *
     * @return array{response: string, relay_state: string|null}|null
     */
    public function take(#[\SensitiveParameter] string $handle): ?array
    {
        $key = self::key($handle);
        $parked = $this->cache->get($key);
        $this->cache->delete($key);

        if (! is_array($parked)) {
            return null;
        }

        $response = $parked['response'] ?? null;
        $relayState = $parked['relay_state'] ?? null;

        if (! is_string($response) || ($relayState !== null && ! is_string($relayState))) {
            return null;
        }

        return ['response' => $response, 'relay_state' => $relayState];
    }

    /**
     * Whether a value has the shape of a handle park() makes.
     */
    public static function isHandle(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) === 1;
    }

    private static function key(string $handle): string
    {
        return self::KEY_PREFIX . hash('sha256', $handle);
    }
}
