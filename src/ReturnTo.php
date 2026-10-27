<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

/**
 * The one rule for a logout return-to URL.
 *
 * A return-to must be an absolute https URL on the service host. Anything
 * else sends the user to the service URL instead, so a logout can never be
 * turned into an open redirect.
 */
final class ReturnTo
{
    /**
     * The URL to send the user to after logout.
     *
     * Returns $returnTo when it is an absolute https URL on the service host.
     * Otherwise it returns the service URL. When the driver has no service
     * URL there is nothing to check against, so $returnTo is used as-is.
     */
    public static function safe(string $returnTo, ?string $serviceUrl): string
    {
        if ($serviceUrl === null) {
            return $returnTo;
        }

        $parts = parse_url($returnTo);

        if ($parts !== false
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === parse_url($serviceUrl, PHP_URL_HOST)
        ) {
            return $returnTo;
        }

        return $serviceUrl;
    }
}
