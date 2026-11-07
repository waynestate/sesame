<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Saml;

use Closure;
use OneLogin\Saml2\Utils;

/**
 * Run php-saml's checks as if the request were at the configured ACS URL.
 *
 * php-saml compares the response's Destination and the subject
 * confirmation's Recipient with "the current URL", which it builds from
 * $_SERVER (and from its own static overrides). That is the wrong source
 * here twice over: the driver checks the response on the login URL (after
 * the ACS parked it), and behind a TLS-terminating proxy $_SERVER does not
 * know the public scheme, host or port. So for the length of one call this
 * sets php-saml's host, scheme and port from the configured ACS URL, points
 * REQUEST_URI at its path, and puts everything back afterwards, even when
 * the call throws. The configured ACS URL, not the request, is what the IdP
 * was told to post to.
 *
 * This is the one place SESAME touches php-saml's global state. An app that
 * also calls Utils::setBaseURL() for its own php-saml use has it reset after
 * each SESAME check.
 */
final class AcsUrl
{
    /**
     * @template T
     *
     * @param Closure(): T $check
     * @return T
     */
    public static function during(string $acsUrl, Closure $check): mixed
    {
        $parts = parse_url($acsUrl);
        $parts = is_array($parts) ? $parts : [];
        $scheme = strtolower($parts['scheme'] ?? 'https');
        // A string: php-saml compares the port with '80' and '443' strictly.
        $port = isset($parts['port']) ? (string) $parts['port'] : ($scheme === 'http' ? '80' : '443');

        $hadUri = array_key_exists('REQUEST_URI', $_SERVER);
        $hadQuery = array_key_exists('QUERY_STRING', $_SERVER);
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        $query = $_SERVER['QUERY_STRING'] ?? null;

        Utils::setBaseURL('');
        Utils::setSelfProtocol($scheme);
        Utils::setSelfHost($parts['host'] ?? '');
        // @phpstan-ignore argument.type (php-saml's docblock says int, but its own setBaseURL() and port checks use strings)
        Utils::setSelfPort($port);
        $_SERVER['REQUEST_URI'] = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        unset($_SERVER['QUERY_STRING']);

        try {
            return $check();
        } finally {
            Utils::setBaseURL('');

            if ($hadUri) {
                $_SERVER['REQUEST_URI'] = $uri;
            } else {
                unset($_SERVER['REQUEST_URI']);
            }

            if ($hadQuery) {
                $_SERVER['QUERY_STRING'] = $query;
            } else {
                unset($_SERVER['QUERY_STRING']);
            }
        }
    }
}
