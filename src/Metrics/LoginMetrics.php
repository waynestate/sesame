<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Metrics;

/**
 * Where SESAME counts the sign-ins it finishes.
 *
 * Optional and off by default: a Sesame built without one counts nothing and
 * behaves exactly as before. The one implementation SESAME ships is
 * PrometheusLoginMetrics, which writes `sesame_logins_total` into a promphp
 * store, so the counts show up on HELMET's /metrics endpoint when the two
 * share a store.
 *
 * The labels are the driver and the outcome, nothing else: never a
 * username, an IP, a session ID or any other value a visitor controls, so
 * the number of series stays small and no personal data reaches a scrape.
 * Counting is best effort. Sesame swallows anything a counter throws, so a
 * broken store never changes a sign-in.
 */
interface LoginMetrics
{
    /**
     * The identity was verified and the app's resolver returned a user.
     */
    public const string SUCCESS = 'success';

    /**
     * The driver refused the sign-in (a bad ticket, token or assertion).
     */
    public const string FAILED = 'failed';

    /**
     * The identity was verified but the app's resolver returned null.
     */
    public const string REFUSED = 'refused';

    /**
     * The identity provider could not be reached or answered with a 5xx
     * (ProviderUnavailable): the outcome to alert on when CAS goes down.
     */
    public const string UNAVAILABLE = 'unavailable';

    /**
     * The outcomes, in the order a dashboard lists them.
     *
     * @var list<string>
     */
    public const array OUTCOMES = [self::SUCCESS, self::FAILED, self::REFUSED, self::UNAVAILABLE];

    /**
     * Count one finished sign-in.
     *
     * @param string $driver The driver's config name (`cas`, `oidc`, ...).
     * @param string $outcome One of the OUTCOMES.
     */
    public function login(string $driver, string $outcome): void;
}
