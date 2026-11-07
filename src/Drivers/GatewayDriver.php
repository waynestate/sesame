<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use DateInterval;
use DateTimeImmutable;
use Lcobucci\JWT\Encoding\CannotDecodeContent;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\Token\InvalidTokenStructure;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\Token\UnsupportedHeaderFound;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\ConstraintViolation;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Clock\SystemClock;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\LoginStep;

/**
 * The gateway driver: signed JWT assertions from the Drawbridge gateway.
 *
 * "Open Sesame", but only the real magic word gets the door. The driver
 * reads one configured header that holds a signed JWT, verifies its
 * signature against the gateway's public key, and checks the issuer,
 * audience and time claims. It never reads a plain identity header
 * (Remote-User, X-Remote-User, or any spelling of them), because a plain
 * header can come from anywhere on the shared network.
 *
 * The request must also come from a trusted proxy: the direct peer
 * (REMOTE_ADDR) must match one of the configured CIDR ranges. A valid
 * assertion from an untrusted peer is still rejected.
 *
 * The algorithm is pinned from config, so a token that claims a different
 * algorithm (or "none") is refused before its signature is even looked at.
 *
 * Every assertion is single-use. The gateway signs a fresh assertion for
 * every request, so a captured one is only dangerous if it can be sent
 * twice: the driver requires a jti claim, refuses assertions that live
 * longer than the configured maximum, and hands the jti to a ReplayGuard,
 * which remembers it for the assertion's lifetime. A second use of the same
 * jti is a replay and is refused.
 */
final class GatewayDriver implements Driver
{
    /**
     * The only algorithms the gateway driver accepts.
     *
     * @var list<string>
     */
    private const ALGORITHMS = ['ES256', 'RS256', 'EdDSA'];

    /**
     * The longest jti the driver accepts, in bytes.
     */
    private const MAX_JTI_LENGTH = 128;

    /**
     * The typ header values of an OAuth2 access token.
     *
     * The oauth2 driver is the only one that accepts these: an access token
     * replayed as a gateway assertion is refused, so the two token kinds stay
     * apart.
     *
     * @var list<string>
     */
    private const ACCESS_TOKEN_TYPES = ['at+jwt', 'application/at+jwt'];

    /**
     * The optional claims that become read-only identity attributes.
     *
     * @var list<string>
     */
    private const ATTRIBUTE_CLAIMS = ['email', 'name', 'affiliations'];

    private readonly Signer $signer;

    /**
     * The single configured key, or null when a map of keys was given.
     */
    private readonly ?Key $publicKey;

    /**
     * The configured keys by kid (for a gateway rotating its signing key),
     * or null when a single key was given.
     *
     * @var array<string, Key>|null
     */
    private readonly ?array $publicKeys;

    private readonly ClockInterface $clock;

    private readonly ?ReplayGuard $replayGuard;

    private readonly int $maxLifetimeSeconds;

    /**
     * @param  string|array<array-key, mixed>  $publicKey  The gateway's public key (PEM, a path to one, or base64 for EdDSA), or a map of kid to key while the gateway rotates its signing key: the token's kid header picks the key that verifies it. A PHP caller can pass a bad string or array, so both are checked at runtime.
     * @param  non-empty-string  $issuer  The expected issuer (iss claim).
     * @param  non-empty-string  $audience  The expected audience (aud claim).
     * @param  list<string>  $trustedProxies  CIDR ranges (or single IPs) the gateway proxy sits behind.
     * @param  int  $maxLifetimeSeconds  The longest an assertion may live (exp minus iat), in seconds.
     * @param  bool  $allowWithoutReplayGuard  Skip the replay guard. For local development only: without a guard, a captured assertion can be replayed until it expires.
     */
    public function __construct(
        private readonly string $header,
        string $algorithm,
        string|array $publicKey,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $leewaySeconds,
        private readonly array $trustedProxies,
        ?ClockInterface $clock = null,
        ?ReplayGuard $replayGuard = null,
        int $maxLifetimeSeconds = 300,
        bool $allowWithoutReplayGuard = false,
    ) {
        if ($replayGuard === null && ! $allowWithoutReplayGuard) {
            throw new InvalidConfig(
                'The gateway driver needs a ReplayGuard so a captured assertion cannot be replayed. Pass one to the driver (or to DriverFactory::make()), or set gateway.allow_without_replay_guard to true for local development only.',
            );
        }

        $this->signer = self::signerFor($algorithm);

        if (is_array($publicKey)) {
            if ($publicKey === []) {
                throw new InvalidConfig(
                    'The config key "gateway.public_keys" must be a non-empty map of kid to key.',
                );
            }

            $keys = [];

            foreach ($publicKey as $kid => $key) {
                if (! is_string($kid) || $kid === '' || ! is_string($key) || $key === '') {
                    throw new InvalidConfig(
                        'Each entry in "gateway.public_keys" must be a non-empty string kid mapped to a non-empty string key.',
                    );
                }

                $keys[$kid] = self::keyFor($key, $algorithm);
            }

            $this->publicKey = null;
            $this->publicKeys = $keys;
        } else {
            if ($publicKey === '') {
                throw new InvalidConfig(
                    'The config key "gateway.public_key" must be a non-empty string.',
                );
            }

            $this->publicKey = self::keyFor($publicKey, $algorithm);
            $this->publicKeys = null;
        }

        $this->clock = $clock ?? new SystemClock();
        $this->replayGuard = $replayGuard;
        $this->maxLifetimeSeconds = $maxLifetimeSeconds;
    }

    public function name(): string
    {
        return 'gateway';
    }

    public function login(ServerRequestInterface $request): LoginStep
    {
        $this->assertTrustedPeer($request);

        $token = $this->parse($this->readToken($request));

        $this->validate($token);

        return new Authenticated($this->identity($token));
    }

    public function logoutUrl(string $returnTo): ?string
    {
        return null;
    }

    public function serviceUrl(): ?string
    {
        return null;
    }

    /**
     * The request must come from a trusted gateway proxy.
     *
     * The direct peer (REMOTE_ADDR) is checked against the configured CIDR
     * ranges. X-Forwarded-For is never consulted: it is set by the client,
     * not the proxy, so it proves nothing.
     */
    private function assertTrustedPeer(ServerRequestInterface $request): void
    {
        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        if (! is_string($remoteAddr) || $remoteAddr === '') {
            throw new AuthenticationFailed(
                'The request has no source address, so the gateway driver cannot confirm it came from a trusted proxy (gateway.untrusted_peer).',
            );
        }

        if (! IpUtils::checkIp($remoteAddr, $this->trustedProxies)) {
            throw new AuthenticationFailed(
                'The request did not come from a trusted gateway proxy (gateway.untrusted_peer).',
            );
        }
    }

    /**
     * Read the assertion from the one configured header, and nothing else.
     *
     * getHeaderLine() is case-insensitive, so the header is found however
     * the proxy spelled it. No other header is ever read: there is no
     * fallback to a plain identity header, because that is the hole this
     * driver exists to close.
     *
     * @return non-empty-string
     */
    private function readToken(ServerRequestInterface $request): string
    {
        $token = trim($request->getHeaderLine($this->header));

        if ($token === '') {
            throw new AuthenticationFailed(
                'The request has no signed assertion in the configured header (gateway.missing_assertion).',
            );
        }

        return $token;
    }

    /**
     * Parse the assertion into a plain (unencrypted) JWT.
     *
     * Anything that is not a well-formed JWS (a missing part, a bad
     * encoding, an encrypted token) is rejected here, before any claim is
     * trusted.
     *
     * @param non-empty-string $tokenString
     */
    private function parse(string $tokenString): UnencryptedToken
    {
        $parser = new Parser(new JoseEncoder());

        try {
            $token = $parser->parse($tokenString);
        } catch (CannotDecodeContent|InvalidTokenStructure|UnsupportedHeaderFound) {
            throw new AuthenticationFailed(
                'The signed assertion is not a valid JWT (gateway.invalid_jwt).',
            );
        }

        if (! $token instanceof UnencryptedToken) {
            throw new AuthenticationFailed(
                'The signed assertion is not a plain JWT (gateway.invalid_jwt).',
            );
        }

        return $token;
    }

    /**
     * Verify the signature and the registered claims.
     *
     * The signature is checked first, with the algorithm pinned from config,
     * so a token that claims a different algorithm (or "none") is refused
     * before its signature is looked at. When a map of keys is configured
     * (gateway.public_keys or gateway.jwks_file, for a gateway rotating its
     * signing key), the token's kid header names the key that verifies it:
     * an unknown kid is refused like a bad signature, and so is a missing
     * kid when two or more keys are listed. With exactly one listed key, a
     * token without a kid is verified with that key. The kid is never
     * echoed back in the message. Then the
     * issuer, audience and time claims (iat, nbf, exp) are checked, with the
     * configured leeway for clock skew.
     */
    private function validate(UnencryptedToken $token): void
    {
        $this->assertNotAnAccessToken($token);

        $key = $this->publicKey;

        if ($key === null) {
            $keys = $this->publicKeys;

            if ($keys === null) {
                throw new InvalidConfig('The gateway driver has no configured public key.');
            }

            $headers = $token->headers();

            if (! $headers->has('kid') && count($keys) === 1) {
                // One listed key and a token that names none: there is
                // nothing to choose between, so the one key verifies it,
                // the same as gateway.public_key would.
                $key = $keys[array_key_first($keys)];
            } else {
                $kid = $headers->get('kid');

                if (! is_string($kid) || ! array_key_exists($kid, $keys)) {
                    throw new AuthenticationFailed(
                        'The signed assertion does not name a configured gateway key (gateway.signature).',
                    );
                }

                $key = $keys[$kid];
            }
        }

        $leeway = new DateInterval('PT' . $this->leewaySeconds . 'S');

        try {
            (new Validator())->assert(
                $token,
                new SignedWith($this->signer, $key),
                new IssuedBy($this->issuer),
                new PermittedFor($this->audience),
                new LooseValidAt($this->clock, $leeway),
            );
        } catch (RequiredConstraintsViolated $e) {
            throw new AuthenticationFailed(
                'The signed assertion was rejected (' . $this->violationReasons($e) . ').',
            );
        }
    }

    /**
     * Refuse an OAuth2 access token presented as a gateway assertion.
     *
     * The oauth2 driver accepts JWTs typed "at+jwt" or
     * "application/at+jwt", so an access token captured from one app could
     * otherwise be replayed here as if it were a signed assertion. A typ
     * header that is one of those values (compared case-insensitively, with
     * any surrounding whitespace ignored) is refused like a bad assertion,
     * and the header value is never echoed back in the message. A missing
     * typ, or any other value such as "JWT", is not an access token marker
     * and is left to the signature and claim checks.
     */
    private function assertNotAnAccessToken(UnencryptedToken $token): void
    {
        $typ = $token->headers()->get('typ');

        if (! is_string($typ)) {
            return;
        }

        if (in_array(strtolower(trim($typ)), self::ACCESS_TOKEN_TYPES, true)) {
            throw new AuthenticationFailed(
                'The signed assertion is typed as an OAuth2 access token, not a gateway assertion (gateway.invalid_assertion).',
            );
        }
    }

    /**
     * The claims the driver requires, and the identity they build.
     *
     * iat, exp and sub must all be present: a token without an issue time or
     * an expiry is not a usable assertion, and a token without a subject has
     * no one to sign in. The subject becomes the username, and the optional
     * email, name and affiliations claims become read-only attributes.
     *
     * Before the identity is built, the assertion is checked for single-use:
     * a usable jti, a bounded lifetime, and no replay (assertSingleUse()).
     */
    private function identity(UnencryptedToken $token): Identity
    {
        $claims = $token->claims();

        foreach ([RegisteredClaims::ISSUED_AT, RegisteredClaims::EXPIRATION_TIME, RegisteredClaims::SUBJECT] as $claim) {
            if (! $claims->has($claim)) {
                throw new AuthenticationFailed(sprintf(
                    'The signed assertion is missing the "%s" claim (gateway.missing_claim).',
                    $claim,
                ));
            }
        }

        $subject = $claims->get(RegisteredClaims::SUBJECT);

        if (! is_string($subject) || $subject === '') {
            throw new AuthenticationFailed(
                'The signed assertion has no usable "sub" claim (gateway.missing_claim).',
            );
        }

        $this->assertSingleUse($claims);

        return new Identity(
            $subject,
            $this->attributes($claims),
            $this->name(),
            $this->clock->now(),
            $this->authTime($claims),
            $this->amr($claims),
            $this->acrs($claims),
        );
    }

    /**
     * The single-use checks: a usable jti, a bounded lifetime, and no replay.
     *
     * The gateway signs a fresh assertion for every request, so a captured
     * assertion is only dangerous if it can be sent twice. The jti is the
     * assertion's one-time ID: the first use records it with the replay
     * guard, and any later use of the same jti is a replay. The lifetime is
     * bounded (exp minus iat) so a stolen assertion stays useful for only a
     * short window, and the guard keeps the jti for that window (plus
     * leeway) after the assertion expires.
     *
     * The replay check runs last: an assertion that fails an earlier check
     * is rejected without consuming its jti, so a rejected token can never
     * be "used up" by an attacker.
     */
    private function assertSingleUse(DataSet $claims): void
    {
        $jti = $claims->get(RegisteredClaims::ID);

        if (! is_string($jti) || $jti === '' || strlen($jti) > self::MAX_JTI_LENGTH) {
            throw new AuthenticationFailed(
                'The signed assertion has no usable "jti" claim (gateway.missing_jti).',
            );
        }

        $issuedAt = $claims->get(RegisteredClaims::ISSUED_AT);
        $expiresAt = $claims->get(RegisteredClaims::EXPIRATION_TIME);

        if (! $issuedAt instanceof DateTimeImmutable || ! $expiresAt instanceof DateTimeImmutable) {
            throw new AuthenticationFailed(
                'The signed assertion has no usable "iat" or "exp" claim (gateway.missing_claim).',
            );
        }

        if ($expiresAt->getTimestamp() - $issuedAt->getTimestamp() > $this->maxLifetimeSeconds) {
            throw new AuthenticationFailed(
                'The signed assertion lives longer than the configured maximum (gateway.lifetime_too_long).',
            );
        }

        if ($this->replayGuard?->seen($jti, $expiresAt) === true) {
            throw new AuthenticationFailed(
                'The signed assertion was already used (gateway.replayed_assertion).',
            );
        }
    }

    /**
     * The verified MFA sign-in time, from the assertion's auth_time claim.
     *
     * The claim is an epoch-seconds integer. A missing claim, or a value that
     * is not a non-negative integer, yields null: the driver never invents a
     * sign-in time, and a bad value can never push it into the future.
     */
    private function authTime(DataSet $claims): ?DateTimeImmutable
    {
        $value = $claims->get('auth_time');

        if (! is_int($value) || $value < 0) {
            return null;
        }

        return new DateTimeImmutable('@' . $value);
    }

    /**
     * The verified authentication methods, from the assertion's amr claim.
     *
     * The claim is a list of strings. A missing claim, or a value that is not
     * an array, yields an empty list, and any non-string entry is dropped:
     * the driver never invents an authentication method.
     *
     * @return list<string>
     */
    private function amr(DataSet $claims): array
    {
        $value = $claims->get('amr');

        if (! is_array($value)) {
            return [];
        }

        $methods = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $methods[] = $item;
            }
        }

        return $methods;
    }

    /**
     * The verified authentication contexts, from the assertion's acrs claim.
     *
     * The claim is a list of strings, present when the gateway required a
     * step-up for the path. A missing claim, or a value that is not an array,
     * yields an empty list, and any entry that is not a string matching the
     * context id shape (c1 through c99) is dropped: the driver never
     * invents an authentication context.
     *
     * @return list<string>
     */
    private function acrs(DataSet $claims): array
    {
        $value = $claims->get('acrs');

        if (! is_array($value)) {
            return [];
        }

        $contexts = [];

        foreach ($value as $item) {
            if (is_string($item) && preg_match('/^c[1-9][0-9]?$/D', $item) === 1) {
                $contexts[] = $item;
            }
        }

        return $contexts;
    }

    /**
     * The optional claims, as read-only identity attributes.
     *
     * @return array<string, list<string>>
     */
    private function attributes(DataSet $claims): array
    {
        $attributes = [];

        foreach (self::ATTRIBUTE_CLAIMS as $claim) {
            $values = $this->attributeValues($claims->get($claim));

            if ($values !== []) {
                $attributes[$claim] = $values;
            }
        }

        return $attributes;
    }

    /**
     * A claim value as a list of strings: a string becomes a one-item list,
     * an array keeps its string items, and anything else is dropped.
     *
     * @return list<string>
     */
    private function attributeValues(mixed $value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }

        if (is_array($value)) {
            $values = [];

            foreach ($value as $item) {
                if (is_string($item) && $item !== '') {
                    $values[] = $item;
                }
            }

            return $values;
        }

        return [];
    }

    /**
     * The reason codes for the violated constraints, for the failure message.
     *
     * The codes are stable and machine-readable; the raw token is never part
     * of the message.
     */
    private function violationReasons(RequiredConstraintsViolated $e): string
    {
        $reasons = [];

        foreach ($e->violations() as $violation) {
            $reasons[] = $this->reasonForViolation($violation);
        }

        return implode(', ', array_unique($reasons));
    }

    private function reasonForViolation(ConstraintViolation $violation): string
    {
        return match (true) {
            $violation->constraint === SignedWith::class => 'gateway.signature',
            $violation->constraint === IssuedBy::class => 'gateway.issuer',
            $violation->constraint === PermittedFor::class => 'gateway.audience',
            str_contains($violation->getMessage(), 'expired') => 'gateway.expired',
            str_contains($violation->getMessage(), 'cannot be used yet') => 'gateway.not_yet_valid',
            str_contains($violation->getMessage(), 'issued in the future') => 'gateway.issued_in_future',
            default => 'gateway.invalid',
        };
    }

    /**
     * The signer for the configured algorithm.
     */
    private static function signerFor(string $algorithm): Signer
    {
        return match ($algorithm) {
            'ES256' => new Sha256(),
            'RS256' => new RsaSha256(),
            'EdDSA' => new Eddsa(),
            default => throw new InvalidConfig(sprintf(
                'The config key "gateway.algorithm" must be one of %s, got "%s".',
                implode(', ', self::ALGORITHMS),
                $algorithm,
            )),
        };
    }

    /**
     * The public key, from a PEM (or a path to one) for the asymmetric
     * signers, or a base64-encoded raw key (or a path to one) for EdDSA.
     *
     * @param non-empty-string $publicKey
     */
    private static function keyFor(string $publicKey, string $algorithm): Key
    {
        if (is_file($publicKey)) {
            return InMemory::file($publicKey);
        }

        if ($algorithm === 'EdDSA') {
            return InMemory::base64Encoded($publicKey);
        }

        return InMemory::plainText($publicKey);
    }
}
