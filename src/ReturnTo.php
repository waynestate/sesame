<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

/**
 * The one rule for a return-to URL (after login or logout).
 *
 * A return-to must be an absolute URL on the same origin as the service
 * URL: the same scheme, the same host and the same effective port (443 for
 * https, 80 for http, when none is written), with no user name or password
 * in it. The scheme must be https; plain http is allowed only for a
 * localhost or 127.0.0.1 service URL, for local development. Anything else
 * sends the user to the service URL instead, so a login or logout can never
 * be turned into an open redirect, not even to another port on the same
 * host (https://app.example.test:8443/ for a service on 443).
 *
 * A driver without a service URL (gateway, oauth2, fake) has no origin to
 * compare against, so its return-to must be a same-origin relative path: it
 * starts with exactly one slash (the second character is neither a slash
 * nor a backslash), it carries no ASCII control character and no backslash
 * anywhere, and parse_url reads it as an array with no scheme, host, user
 * or pass. Anything else sends the user to `/`, so a login or logout can
 * never be turned into an open redirect there either.
 */
final class ReturnTo
{
    /**
     * Hosts where plain http is accepted, for local development only.
     *
     * @var list<string>
     */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    /**
     * The URL to send the user to.
     *
     * Returns $returnTo when it passes the rule above. Otherwise it returns
     * the service URL. When the driver has no service URL, $returnTo is
     * returned only when it is a same-origin relative path (one leading
     * slash, no second slash or backslash, no control characters and no
     * backslash anywhere, and no scheme, host, user or pass in its
     * parse_url parts); anything else returns '/'.
     */
    public static function safe(string $returnTo, ?string $serviceUrl): string
    {
        if ($serviceUrl === null) {
            return self::relativePath($returnTo) ? $returnTo : '/';
        }

        return self::sameOrigin($returnTo, $serviceUrl) ? $returnTo : $serviceUrl;
    }

    /**
     * Whether $returnTo is a same-origin relative path: it starts with
     * exactly one slash (the second character is neither a slash nor a
     * backslash), it carries no ASCII control character (0x00 to 0x1F and
     * 0x7F) and no backslash anywhere, and parse_url reads it as an array
     * with no scheme, host, user or pass.
     */
    private static function relativePath(string $returnTo): bool
    {
        if ($returnTo === '' || $returnTo[0] !== '/') {
            return false;
        }

        $second = $returnTo[1] ?? '';

        if ($second === '/' || $second === '\\') {
            return false;
        }

        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $returnTo) === 1) {
            return false;
        }

        $parts = parse_url($returnTo);

        return is_array($parts)
            && ! isset($parts['scheme'])
            && ! isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    private static function sameOrigin(string $returnTo, string $serviceUrl): bool
    {
        $target = parse_url($returnTo);
        $service = parse_url($serviceUrl);

        if (! is_array($target) || ! is_array($service)) {
            return false;
        }

        if (isset($target['user']) || isset($target['pass'])) {
            return false;
        }

        $scheme = strtolower($target['scheme'] ?? '');
        $host = strtolower($target['host'] ?? '');
        $serviceScheme = strtolower($service['scheme'] ?? '');
        $serviceHost = strtolower($service['host'] ?? '');

        if ($host === '' || $host !== $serviceHost || $scheme !== $serviceScheme) {
            return false;
        }

        if ($scheme !== 'https' && ! ($scheme === 'http' && in_array($host, self::LOCAL_HOSTS, true))) {
            return false;
        }

        return self::port($target, $scheme) === self::port($service, $serviceScheme);
    }

    /**
     * The effective port: the written one, or the scheme's default.
     *
     * @param array<string, int|string> $parts
     */
    private static function port(array $parts, string $scheme): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return $scheme === 'http' ? 80 : 443;
    }
}
