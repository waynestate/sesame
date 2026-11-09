<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Exception;

/**
 * The identity provider could not be reached, or did not answer in time.
 *
 * Raised when the call a driver makes to its provider fails in transport (the
 * PSR-18 client threw: no route, DNS, TLS, the connect or total timeout ran
 * out), and when the CAS server answers the ticket check with a 5xx. Nobody
 * is signed in: the check fails closed, as every refusal does. It is a
 * subclass of AuthenticationFailed, so an app that catches AuthenticationFailed
 * already catches it; catch this one first to answer 503 ("the university
 * sign-in is not answering, try again in a minute") instead of a refusal.
 *
 * Nothing from the HTTP client is chained: its exceptions hold the request,
 * and the request can hold the CAS ticket or a client secret. The message
 * names the driver's reason code (for example `cas.unavailable`) for the
 * logs, nothing more.
 *
 * Open sesame works better when someone is home.
 */
final class ProviderUnavailable extends AuthenticationFailed {}
