<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use Composer\InstalledVersions;
use DateTimeImmutable;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\AuthnRequest;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use OneLogin\Saml2\ValidationError;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Clock\SystemClock;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Drivers\Saml\AcsUrl;
use Waynestate\Sesame\Drivers\Saml\IdpMetadata;
use Waynestate\Sesame\Drivers\Saml\ParkedResponses;
use Waynestate\Sesame\Drivers\Saml\PendingRequests;
use Waynestate\Sesame\Drivers\Saml\SamlOptions;
use Waynestate\Sesame\Drivers\Saml\SamlResponse;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\LoginStep;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\RemembersReturnTo;
use Waynestate\Sesame\ReturnTo;
use Waynestate\Sesame\SessionPort;

/**
 * The saml driver: a SAML 2.0 service provider for a Shibboleth-style IdP.
 *
 * Open sesame, in a language with a schema. The driver stands on
 * onelogin/php-saml (docs/saml-library-choice.md) and adds SESAME's rules on
 * top. A sign-in takes three requests:
 *
 *   1. GET login URL: the start. The driver builds an AuthnRequest
 *      (HTTP-Redirect binding, signed when saml.sign_authn_requests is on),
 *      keeps its ID in the app's session (single use, expiring), and answers
 *      with a Redirect to the IdP. A checked return_to rides along as the
 *      RelayState.
 *   2. POST ACS URL: the IdP's answer, a cross-site POST that carries no
 *      SameSite=Lax session cookie. receive() parks the raw response in the
 *      PSR-16 cache under a random handle, without touching the session,
 *      and answers with a Redirect (sent as 303) back to the login URL.
 *   3. GET login URL with saml_handle: the finish. The session cookie is
 *      back (a top-level GET), so the driver takes the parked response and
 *      the pending request it answers (both single use), and php-saml
 *      validates the message in strict mode: XML schema, the signature and
 *      what it covers, Destination and Recipient against the configured ACS
 *      URL, Audience, Issuer, InResponseTo, the time conditions and the
 *      bearer subject confirmation. SESAME then requires a signed assertion,
 *      SHA-2 signatures only, an IssueInstant within the leeway and no older
 *      than saml.max_assertion_age_seconds, and an assertion ID the replay
 *      guard has never seen.
 *
 * IdP-initiated (unsolicited) responses are refused: a sign-in must start at
 * the app (S35). The identity's username comes from one mapped attribute
 * (accessid, from uid, by default), the other mapped attributes (email,
 * name, affiliations, eppn) are passed to the resolver under SESAME's names,
 * and the AuthnStatement gives the MFA facts: AuthnInstant is the sign-in
 * time, and an AuthnContextClassRef in saml.mfa_contexts (the REFEDS MFA
 * profile by default) counts as MFA. There is no single logout: logout is
 * local, and the IdP's session lives on.
 */
final class SamlDriver implements Driver, RemembersReturnTo
{
    /**
     * The class whose presence means onelogin/php-saml is installed.
     */
    public const LIBRARY_CLASS = Response::class;

    public const LIBRARY_PACKAGE = 'onelogin/php-saml';

    /**
     * The lowest php-saml release SESAME accepts (its security floor). 4.3.1 fixed
     * CVE-2025-66475; 4.3.2 requires an xmlseclibs with the fix for
     * CVE-2026-32313. composer.json's "conflict" holds the same floor.
     */
    public const LIBRARY_FLOOR = '4.3.2';

    public const XMLSECLIBS_PACKAGE = 'robrichards/xmlseclibs';

    /**
     * The lowest xmlseclibs release SESAME accepts: 3.1.5 fixed
     * CVE-2026-32313 (and 3.1.4 CVE-2025-66578).
     */
    public const XMLSECLIBS_FLOOR = '3.1.5';

    /**
     * The query parameter that carries a parked response's handle from the
     * ACS to the login URL.
     */
    public const HANDLE_PARAM = 'saml_handle';

    /**
     * The longest SAMLResponse the ACS accepts, in bytes of base64. A signed
     * response with a handful of attributes is around ten kilobytes.
     */
    public const MAX_RESPONSE_BYTES = 262_144;

    private const MAX_RETURN_TO_LENGTH = 2048;

    /**
     * How old a step-up sign-in may be, in seconds.
     */
    public const STEP_UP_MAX_AGE = 300;

    private readonly ClockInterface $clock;

    private readonly IdpMetadata $metadata;

    private readonly PendingRequests $pending;

    private readonly ParkedResponses $parked;

    private readonly ReplayGuard $replayGuard;

    private ?string $returnTo = null;

    public function __construct(
        private readonly SamlOptions $options,
        SessionPort $session,
        CacheInterface $cache,
        ?ReplayGuard $replayGuard = null,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?ClockInterface $clock = null,
    ) {
        self::assertLibraryInstalled();

        $this->clock = $clock ?? new SystemClock();
        $this->metadata = new IdpMetadata($options, $http, $requests, $cache);
        $this->pending = new PendingRequests($session, $this->clock, $options->requestTtlSeconds);
        $this->parked = new ParkedResponses($cache);
        $this->replayGuard = $replayGuard ?? new Psr16ReplayGuard($cache, $options->leewaySeconds, $this->clock);

        // A metadata file is part of the deploy: a bad one stops the app at
        // boot, not at the first sign-in.
        if ($options->idpMetadataXml !== null) {
            $this->metadata->idp();
        }
    }

    /**
     * Fail with a clear error when onelogin/php-saml is missing, or when the
     * installed php-saml or xmlseclibs is older than SESAME's floor.
     *
     * SESAME keeps protocol libraries optional: the saml driver is the only
     * part that needs this one, so it sits in "suggest", and this check
     * turns a missing class into an instruction. The floor is checked
     * against Composer's installed versions; a dev branch (no release
     * number) is refused too, because nothing says it holds the fixes.
     *
     * @throws InvalidConfig When the library is missing or below the floor.
     */
    public static function assertLibraryInstalled(string $class = self::LIBRARY_CLASS): void
    {
        if (! class_exists($class)) {
            throw new InvalidConfig('The saml driver needs onelogin/php-saml. Install it with: composer require onelogin/php-saml');
        }

        foreach ([self::LIBRARY_PACKAGE => self::LIBRARY_FLOOR, self::XMLSECLIBS_PACKAGE => self::XMLSECLIBS_FLOOR] as $package => $floor) {
            $version = self::installedVersion($package);

            if ($version !== null && ! self::meetsFloor($version, $floor)) {
                throw new InvalidConfig(sprintf(
                    'The saml driver needs %s %s or later; %s is installed. Update it with: composer update %s',
                    $package,
                    $floor,
                    $version,
                    $package,
                ));
            }
        }
    }

    /**
     * The installed version of a package as Composer recorded it, or null
     * when Composer's runtime API is not there to ask.
     */
    public static function installedVersion(string $package): ?string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled($package)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($package);
    }

    /**
     * Whether a version string is a release at or above the floor.
     */
    public static function meetsFloor(string $version, string $floor): bool
    {
        $version = ltrim($version, 'vV');

        if (preg_match('/^\d+\.\d+\.\d+/', $version, $match) !== 1) {
            return false;
        }

        return version_compare($match[0], $floor, '>=');
    }

    public function name(): string
    {
        return 'saml';
    }

    /**
     * Start a sign-in, or finish one whose response the ACS parked.
     */
    public function login(#[\SensitiveParameter] ServerRequestInterface $request): LoginStep
    {
        $query = $request->getQueryParams();
        $this->returnTo = null;

        if (array_key_exists(self::HANDLE_PARAM, $query)) {
            return $this->finish($query[self::HANDLE_PARAM]);
        }

        return $this->start($query);
    }

    /**
     * The ACS: take the IdP's POST, park it, and send the browser back to
     * the login URL.
     *
     * Call it on the ACS URL only, and never start or touch the session on
     * that request: the browser sends no Lax session cookie on the IdP's
     * cross-site POST, so a session started here would replace the real
     * one. Nothing is checked here beyond the shape and the size; the
     * message is validated on the login URL, where the session is back.
     * Send the answer as a 303, so the browser follows it with a GET.
     *
     * @throws AuthenticationFailed When the request is not a usable POST.
     */
    public function receive(#[\SensitiveParameter] ServerRequestInterface $request): Redirect
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            throw new AuthenticationFailed('The ACS takes the IdP\'s POST only (saml.acs).');
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $response = $body['SAMLResponse'] ?? null;

        if (! is_string($response) || $response === '' || strlen($response) > self::MAX_RESPONSE_BYTES
            || preg_match('/^[A-Za-z0-9+\/=\r\n]+$/D', $response) !== 1) {
            throw new AuthenticationFailed('The ACS request carries no usable SAMLResponse (saml.acs).');
        }

        $relayState = $body['RelayState'] ?? null;

        if (! is_string($relayState) || $relayState === '' || strlen($relayState) > self::MAX_RETURN_TO_LENGTH) {
            $relayState = null;
        }

        $handle = $this->parked->park($response, $relayState);
        $serviceUrl = $this->options->serviceUrl;

        return new Redirect($serviceUrl . (str_contains($serviceUrl, '?') ? '&' : '?') . self::HANDLE_PARAM . '=' . $handle);
    }

    /**
     * The SP's metadata XML, for the IdP's administrators: the entity ID,
     * the ACS URL (HTTP-POST), WantAssertionsSigned="true", and the SP
     * certificate when one is configured. Building it needs no IdP.
     *
     * @throws InvalidConfig When php-saml refuses the settings.
     */
    public function metadata(): string
    {
        try {
            $settings = new Settings($this->settingsArray(null), true);
            $xml = $settings->getSPMetadata();
            $errors = $settings->validateMetadata($xml);
        } catch (\Throwable $e) {
            throw new InvalidConfig('The SP metadata could not be built (saml.metadata): ' . self::safeMessage($e));
        }

        if ($errors !== []) {
            throw new InvalidConfig('The SP metadata did not validate (saml.metadata).');
        }

        return $xml;
    }

    /**
     * Null: SESAME does not support single logout (the slo setting is
     * refused at boot), so logout is local and the IdP's session lives on.
     */
    public function logoutUrl(string $returnTo): ?string
    {
        return null;
    }

    /**
     * The login URL: where a sign-in starts and finishes, and the host every
     * return-to must stay on.
     */
    public function serviceUrl(): string
    {
        return $this->options->serviceUrl;
    }

    /**
     * The ACS URL the IdP posts its response to.
     */
    public function acsUrl(): string
    {
        return $this->options->acsUrl;
    }

    public function returnTo(): ?string
    {
        return $this->returnTo;
    }

    /**
     * What var_dump and print_r show: the options (with the key redacted).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['options' => $this->options, 'returnTo' => $this->returnTo];
    }

    /**
     * Start a sign-in: remember the request ID, then send the user to the
     * IdP with the AuthnRequest.
     *
     * @param array<mixed> $query
     */
    private function start(array $query): Redirect
    {
        $stepUp = in_array($query['step_up'] ?? null, ['1', 'true'], true);
        $relayState = $this->safeReturnTo($query['return_to'] ?? null);
        $idp = $this->metadata->idp();

        try {
            $auth = new Auth($this->settingsArray($idp));
            // ForceAuthn on a step-up: the IdP must ask for credentials
            // again. No NameIDPolicy: the username comes from an attribute.
            $request = new AuthnRequest($auth->getSettings(), $stepUp, false, false);
            $samlRequest = $request->getRequest();
            $requestId = $request->getId();

            $params = ['SAMLRequest' => $samlRequest];

            if ($relayState !== null) {
                $params['RelayState'] = $relayState;
            }

            if ($this->options->signAuthnRequests) {
                $params['SigAlg'] = SamlResponse::ALLOWED_ALGORITHMS[0];
                // @phpstan-ignore argument.type (php-saml leaves a null RelayState out of the signed string; its docblock says string)
                $params['Signature'] = $auth->buildRequestSignature($samlRequest, $relayState, SamlResponse::ALLOWED_ALGORITHMS[0]);
            }

            $url = Utils::redirect($idp['ssoUrl'], $params, true);
        } catch (\Throwable $e) {
            throw new AuthenticationFailed('The AuthnRequest could not be built (saml.authn_request): ' . self::safeMessage($e));
        }

        if ($requestId === '') {
            throw new AuthenticationFailed('The AuthnRequest could not be built (saml.authn_request).');
        }

        $this->pending->put($requestId, $stepUp);

        return new Redirect($url);
    }

    /**
     * Finish a sign-in: take the parked response and its pending request,
     * validate the message, and build the identity.
     */
    private function finish(#[\SensitiveParameter] mixed $handle): Authenticated
    {
        if (! ParkedResponses::isHandle($handle)) {
            throw new AuthenticationFailed('The sign-in has no usable response handle (saml.handle).');
        }

        /** @var string $handle */
        $parked = $this->parked->take($handle);

        if ($parked === null) {
            throw new AuthenticationFailed('The SAML response is unknown, used or older than two minutes (saml.handle).');
        }

        $idp = $this->metadata->idp();

        try {
            $settings = new Settings($this->settingsArray($idp));
        } catch (\Throwable $e) {
            throw new AuthenticationFailed('php-saml refused the settings (saml.settings): ' . self::safeMessage($e));
        }

        try {
            $response = new SamlResponse($settings, preg_replace('/[\r\n]+/', '', $parked['response']) ?? '');
        } catch (\Throwable) {
            throw new AuthenticationFailed('The SAML response is not well-formed XML, or carries a DOCTYPE (saml.response).');
        }

        $requestId = $response->inResponseTo();

        if ($requestId === null) {
            throw new AuthenticationFailed('The SAML response answers no request; sign-ins must start at the app (saml.unsolicited).');
        }

        // Taken out before validation, so the request is spent even when
        // the rest of the sign-in fails.
        $pending = $this->pending->take($requestId);

        if ($pending === null) {
            throw new AuthenticationFailed('The SAML response answers a request this browser did not send, or one already used or expired (saml.in_response_to).');
        }

        $valid = AcsUrl::during($this->options->acsUrl, static fn(): bool => $response->isValid($requestId));

        if ($valid !== true) {
            throw new AuthenticationFailed(sprintf('The SAML response was refused (saml.invalid: %s).', self::reason($response->getErrorException())));
        }

        foreach ($response->signatureAlgorithms() as $algorithm) {
            if (! in_array($algorithm, SamlResponse::ALLOWED_ALGORITHMS, true)) {
                throw new AuthenticationFailed('The SAML response is signed with an algorithm outside SHA-2 (saml.algorithm).');
            }
        }

        $now = $this->clock->now()->getTimestamp();
        $leeway = $this->options->leewaySeconds;
        $issued = $response->issueInstant();

        if ($issued === null) {
            throw new AuthenticationFailed('The SAML assertion has no IssueInstant (saml.issue_instant).');
        }

        if ($issued > $now + $leeway) {
            throw new AuthenticationFailed('The SAML assertion was issued in the future; check the clocks (saml.issue_instant).');
        }

        if ($now - $issued > $this->options->maxAssertionAgeSeconds + $leeway) {
            throw new AuthenticationFailed('The SAML assertion is older than saml.max_assertion_age_seconds (saml.too_old).');
        }

        $assertionId = $response->getAssertionId();
        $issuer = $response->assertionIssuer() ?? $idp['entityId'];

        if (! is_string($assertionId) || $assertionId === '') {
            throw new AuthenticationFailed('The SAML assertion has no ID (saml.assertion_id).');
        }

        // The assertion cannot be accepted after its issue time plus the
        // maximum age and the leeway, so its ID need not be kept longer.
        $expiresAt = new DateTimeImmutable('@' . ($issued + $this->options->maxAssertionAgeSeconds + $leeway));

        if ($this->replayGuard->seen('saml.' . $issuer . '.' . $assertionId, $expiresAt)) {
            throw new AuthenticationFailed('The SAML assertion has been used before (saml.replay).');
        }

        $authnInstant = $response->authnInstant();

        if ($pending['step_up']) {
            $this->assertFreshSignIn($authnInstant, $pending['started'], $now);
        }

        $identity = $this->identity($response, $issuer, $authnInstant);
        $this->returnTo = $this->safeReturnTo($parked['relay_state']);

        return new Authenticated($identity);
    }

    /**
     * A step-up sign-in must be fresh: an AuthnInstant is required, not in
     * the future (beyond the leeway), not before the sign-in started (the
     * AuthnRequest said ForceAuthn), and within STEP_UP_MAX_AGE.
     */
    private function assertFreshSignIn(?int $authnInstant, int $started, int $now): void
    {
        $leeway = $this->options->leewaySeconds;

        if ($authnInstant === null) {
            throw new AuthenticationFailed('The step-up sign-in has no AuthnInstant (saml.step_up).');
        }

        if ($authnInstant > $now + $leeway) {
            throw new AuthenticationFailed('The step-up sign-in has an AuthnInstant in the future (saml.step_up).');
        }

        if ($authnInstant < $started - $leeway) {
            throw new AuthenticationFailed('The step-up sign-in reused an earlier sign-in (saml.step_up).');
        }

        if ($now - $authnInstant > self::STEP_UP_MAX_AGE + $leeway) {
            throw new AuthenticationFailed('The step-up sign-in is too old (saml.step_up).');
        }
    }

    /**
     * The identity, mapped from the validated assertion.
     */
    private function identity(SamlResponse $response, string $issuer, ?int $authnInstant): Identity
    {
        try {
            $byName = $response->getAttributes();
            $byFriendlyName = $response->getAttributesWithFriendlyName();
            $nameId = $response->getNameId();
        } catch (\Throwable $e) {
            throw new AuthenticationFailed(sprintf('The SAML assertion\'s attributes could not be read (saml.attributes: %s).', self::reason($e)));
        }

        $attributes = [];

        foreach ($this->options->attributes as $name => $sources) {
            foreach ($sources as $source) {
                $values = self::strings($byName[$source] ?? $byFriendlyName[$source] ?? null);

                if ($values !== []) {
                    $attributes[$name] = $values;

                    break;
                }
            }
        }

        $username = trim($attributes[$this->options->usernameAttribute][0] ?? '');

        if ($username === '' || strlen($username) > 256 || preg_match('/[\x00-\x20\x7F]/', $username) === 1) {
            throw new AuthenticationFailed(sprintf(
                'The SAML assertion has no usable "%s" attribute (saml.missing_attribute). Ask the IdP to release it to this SP, or point SESAME_SAML_USERNAME_ATTRIBUTE (saml.username_attribute) and saml.attributes at an attribute it does release.',
                $this->options->usernameAttribute,
            ));
        }

        $classRef = $response->authnContextClassRef();
        $extra = [
            'issuer' => [$issuer],
            'name_id' => self::strings($nameId),
            'authn_context' => self::strings($classRef),
        ];

        foreach ($extra as $name => $values) {
            if ($values !== [] && ! isset($attributes[$name])) {
                $attributes[$name] = $values;
            }
        }

        $amr = [];

        if (($classRef !== null && in_array($classRef, $this->options->mfaContexts, true)) || $this->options->assumeMfa) {
            $amr[] = 'mfa';
        }

        $now = $this->clock->now();
        $authTime = $authnInstant !== null && $authnInstant >= 0 ? new DateTimeImmutable('@' . $authnInstant) : null;

        // The sign-in time is the IdP's AuthnInstant, unless it is missing
        // or more than five minutes ahead of the clock.
        $authenticatedAt = $authTime !== null && $authTime <= $now->modify('+5 minutes') ? $authTime : $now;

        // The AuthnContextClassRef is also the identity's authentication
        // context, so the sesame.context middleware can require one (for
        // example the REFEDS MFA profile) by name.
        return new Identity($username, $attributes, $this->name(), $authenticatedAt, $authTime, $amr, self::strings($classRef));
    }

    /**
     * php-saml's settings array. With no IdP (for the SP metadata) only the
     * SP half is filled.
     *
     * @param array{entityId: string, ssoUrl: string, certs: list<string>}|null $idp
     * @return array<string, mixed>
     */
    private function settingsArray(?array $idp): array
    {
        $settings = [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $this->options->spEntityId,
                'assertionConsumerService' => [
                    'url' => $this->options->acsUrl,
                    'binding' => Constants::BINDING_HTTP_POST,
                ],
                'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
                'x509cert' => $this->options->spCert ?? '',
                'privateKey' => $this->options->spKey() ?? '',
            ],
            'security' => [
                'authnRequestsSigned' => $this->options->signAuthnRequests,
                'wantAssertionsSigned' => true,
                'wantMessagesSigned' => $this->options->wantMessagesSigned,
                'wantNameId' => false,
                'wantXMLValidation' => true,
                'requireDestination' => true,
                'destinationStrictlyMatches' => true,
                'relaxDestinationValidation' => false,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'requestedAuthnContext' => $this->options->authnContexts === [] ? false : $this->options->authnContexts,
                'signatureAlgorithm' => SamlResponse::ALLOWED_ALGORITHMS[0],
                'digestAlgorithm' => SamlResponse::ALLOWED_ALGORITHMS[3],
                'allowRepeatAttributeName' => false,
                'allowRSA15KeyTransport' => false,
            ],
        ];

        if ($idp !== null) {
            $settings['idp'] = [
                'entityId' => $idp['entityId'],
                'singleSignOnService' => [
                    'url' => $idp['ssoUrl'],
                    'binding' => Constants::BINDING_HTTP_REDIRECT,
                ],
            ];

            if (count($idp['certs']) === 1) {
                $settings['idp']['x509cert'] = $idp['certs'][0];
            } else {
                $settings['idp']['x509certMulti'] = ['signing' => $idp['certs']];
            }
        }

        return $settings;
    }

    /**
     * A short, safe reason for a php-saml failure: the ValidationError or
     * Error code by name (for example "wrong_audience"), never the message,
     * which can echo values from the attacker's XML.
     */
    private static function reason(?\Throwable $e): string
    {
        if ($e instanceof ValidationError) {
            foreach ((new ReflectionClass(ValidationError::class))->getConstants() as $name => $code) {
                if ($code === $e->getCode()) {
                    return strtolower($name);
                }
            }
        }

        return 'rejected';
    }

    /**
     * A php-saml settings or build error, without anything secret in it:
     * php-saml's own error keys (for example "sp_acs_url_invalid") only.
     */
    private static function safeMessage(\Throwable $e): string
    {
        $message = $e->getMessage();

        return preg_match('/^[A-Za-z0-9 _.,:\'()-]{1,300}$/D', $message) === 1 ? $message : get_class($e);
    }

    /**
     * A value as a list of non-empty strings.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return trim($value) === '' ? [] : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * The return-to for after the sign-in, or null when none was asked for
     * or it fails the ReturnTo rule (an absolute https URL with the login
     * URL's scheme, host and port, and no credentials). Used both for the
     * RelayState the driver sends and for the RelayState that comes back,
     * which is never trusted as it arrives.
     */
    private function safeReturnTo(mixed $returnTo): ?string
    {
        if (! is_string($returnTo) || $returnTo === '' || strlen($returnTo) > self::MAX_RETURN_TO_LENGTH
            || preg_match('/[\x00-\x20\x7F\\\\]/', $returnTo) === 1) {
            return null;
        }

        return ReturnTo::safe($returnTo, $this->options->serviceUrl) === $returnTo ? $returnTo : null;
    }
}
