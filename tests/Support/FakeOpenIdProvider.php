<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * An OpenID provider that lives in the test process.
 *
 * It answers the discovery document, the key set and the token endpoint
 * through a Guzzle handler, so the oidc driver's real HTTP code runs (the
 * PSR-18 calls and league/oauth2-client's token request) without a socket.
 * authorize() plays the provider's sign-in page: it checks the request the
 * driver built, issues a code bound to the PKCE challenge, the nonce and
 * the redirect URI, and returns the callback query. The token endpoint
 * checks the client's credentials and the PKCE verifier before it signs an
 * ID token with a locally generated RSA key.
 */
final class FakeOpenIdProvider
{
    public const ISSUER = 'https://login.example.test/tenant-for-tests/v2.0';

    public const CLIENT_ID = 'client-id-for-tests';

    public const CLIENT_SECRET = 'Sup3r~Secret+Value:for/tests';

    public const REDIRECT_URI = 'https://app.example.test/login/callback';

    public const KID = 'fake-key-1';

    public const AUTHORIZE_URL = 'https://login.example.test/tenant-for-tests/oauth2/v2.0/authorize';

    public const TOKEN_URL = 'https://login.example.test/tenant-for-tests/oauth2/v2.0/token';

    public const JWKS_URL = 'https://login.example.test/tenant-for-tests/discovery/v2.0/keys';

    public const END_SESSION_URL = 'https://login.example.test/tenant-for-tests/oauth2/v2.0/logout';

    /** @var array{private: string, public: string, jwk: array<string, string>}|null */
    private static ?array $key = null;

    /** @var list<RequestInterface> */
    public array $requests = [];

    public int $discoveryFetches = 0;

    public int $jwksFetches = 0;

    public int $tokenRequests = 0;

    /** @var array<string, mixed> Extra or replaced ID token claims. */
    public array $claimOverrides = [];

    /** @var list<string> ID token claims to leave out. */
    public array $claimRemovals = [];

    /** @var array<string, mixed> Extra or replaced ID token header fields. */
    public array $headerOverrides = [];

    /** "rs256" (the default), "none", or "hs256-with-public-key". */
    public string $signing = 'rs256';

    /** The kid published in the key set. */
    public string $publishedKid = self::KID;

    /** @var array<string, mixed>|null A token response to send instead of the normal one. */
    public ?array $tokenResponse = null;

    /** @var array<string, mixed>|null A discovery document to send instead of the normal one. */
    public ?array $discoveryDocument = null;

    /** @var array<string, array{challenge: string, nonce: string, redirect_uri: string, used: bool}> */
    private array $codes = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    /**
     * A Guzzle client (PSR-18 too) whose every request lands here.
     */
    public function client(): Client
    {
        $handler = function (RequestInterface $request): PromiseInterface {
            $this->requests[] = $request;

            return Create::promiseFor($this->handle($request));
        };

        return new Client(['handler' => HandlerStack::create($handler), 'http_errors' => false]);
    }

    /**
     * A client whose every request fails, for the "unreachable" tests.
     */
    public static function brokenClient(): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler([
            new RuntimeException('connection refused'),
            new RuntimeException('connection refused'),
            new RuntimeException('connection refused'),
        ]))]);
    }

    /**
     * Play the sign-in page: check the authorization request and answer
     * with the callback's query parameters.
     *
     * @return array{code: string, state: string}
     */
    public function authorize(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ('https://' . ($parts['host'] ?? '') . ($parts['path'] ?? '')) !== self::AUTHORIZE_URL) {
            throw new RuntimeException('The authorization request went to the wrong place: ' . $url);
        }

        parse_str($parts['query'] ?? '', $query);

        foreach (['client_id' => self::CLIENT_ID, 'response_type' => 'code', 'code_challenge_method' => 'S256', 'response_mode' => 'query'] as $name => $expected) {
            if (($query[$name] ?? null) !== $expected) {
                throw new RuntimeException(sprintf('The authorization request has %s=%s.', $name, var_export($query[$name] ?? null, true)));
            }
        }

        $scope = $query['scope'] ?? '';

        if (! is_string($scope) || ! in_array('openid', explode(' ', $scope), true)) {
            throw new RuntimeException('The authorization request has no openid scope.');
        }

        $challenge = $query['code_challenge'] ?? null;
        $nonce = $query['nonce'] ?? null;
        $state = $query['state'] ?? null;
        $redirect = $query['redirect_uri'] ?? null;

        if (! is_string($challenge) || ! is_string($nonce) || ! is_string($state) || ! is_string($redirect)) {
            throw new RuntimeException('The authorization request is missing a parameter.');
        }

        $code = 'code-' . bin2hex(random_bytes(8));
        $this->codes[$code] = ['challenge' => $challenge, 'nonce' => $nonce, 'redirect_uri' => $redirect, 'used' => false];

        return ['code' => $code, 'state' => $state];
    }

    /**
     * Sign an ID token with the provider's key (or as the test asks).
     *
     * @param array<string, mixed> $claims
     */
    public function sign(array $claims): string
    {
        $key = self::key();
        $header = array_merge(['kid' => self::KID], $this->headerOverrides);

        if ($this->signing === 'none') {
            $head = self::b64(json_encode(array_merge(['alg' => 'none', 'typ' => 'JWT'], $header), JSON_THROW_ON_ERROR));

            return $head . '.' . self::b64(json_encode($claims, JSON_THROW_ON_ERROR)) . '.';
        }

        if ($this->signing === 'hs256-with-public-key') {
            return JWT::encode($claims, $key['public'], 'HS256', null, $header);
        }

        $alg = is_string($header['alg'] ?? null) ? $header['alg'] : 'RS256';
        unset($header['alg']);

        return JWT::encode($claims, $key['private'], $alg, null, $header);
    }

    /**
     * The normal discovery document.
     *
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::AUTHORIZE_URL,
            'token_endpoint' => self::TOKEN_URL,
            'jwks_uri' => self::JWKS_URL,
            'end_session_endpoint' => self::END_SESSION_URL,
            'response_types_supported' => ['code'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ];
    }

    private function handle(RequestInterface $request): Response
    {
        $uri = (string) $request->getUri();

        if ($uri === self::ISSUER . '/.well-known/openid-configuration') {
            $this->discoveryFetches++;

            return self::json($this->discoveryDocument ?? $this->discovery());
        }

        if ($uri === self::JWKS_URL) {
            $this->jwksFetches++;

            return self::json(['keys' => [array_merge(self::key()['jwk'], ['kid' => $this->publishedKid])]]);
        }

        if ($uri === self::TOKEN_URL && $request->getMethod() === 'POST') {
            $this->tokenRequests++;

            return $this->token($request);
        }

        return new Response(404, [], 'not found');
    }

    private function token(RequestInterface $request): Response
    {
        parse_str((string) $request->getBody(), $form);

        $clientId = $form['client_id'] ?? null;
        $secret = $form['client_secret'] ?? null;
        $basic = $request->getHeaderLine('Authorization');

        if ($basic !== '') {
            $decoded = base64_decode(substr($basic, 6), true);
            [$id, $pass] = explode(':', (string) $decoded, 2) + [1 => ''];
            $clientId = urldecode($id);
            $secret = urldecode($pass);
        }

        if ($clientId !== self::CLIENT_ID || $secret !== self::CLIENT_SECRET) {
            return self::json(['error' => 'invalid_client'], 401);
        }

        $code = $form['code'] ?? null;
        $code = is_string($code) ? $code : '';
        $grant = $this->codes[$code] ?? null;

        if (($form['grant_type'] ?? null) !== 'authorization_code' || $grant === null || $grant['used']) {
            return self::json(['error' => 'invalid_grant'], 400);
        }

        $this->codes[$code]['used'] = true;

        if (($form['redirect_uri'] ?? null) !== $grant['redirect_uri']) {
            return self::json(['error' => 'invalid_grant'], 400);
        }

        $verifier = $form['code_verifier'] ?? null;
        $challenge = is_string($verifier) ? rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=') : '';

        if (! hash_equals($grant['challenge'], $challenge)) {
            return self::json(['error' => 'invalid_grant'], 400);
        }

        if ($this->tokenResponse !== null) {
            return self::json($this->tokenResponse);
        }

        $now = $this->clock->now()->getTimestamp();
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'subject-123',
            'oid' => 'object-456',
            'tid' => 'tenant-for-tests',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'nonce' => $grant['nonce'],
            'auth_time' => $now - 120,
            'accessid' => 'ab1234',
            'preferred_username' => 'ab1234@example.test',
            'email' => 'jane.doe@example.test',
            'name' => 'Jane Doe',
            'wsuRoles' => ['employee', 'staff'],
        ], $this->claimOverrides);

        foreach ($this->claimRemovals as $name) {
            unset($claims[$name]);
        }

        return self::json([
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'access_token' => 'access-token-not-used',
            'id_token' => $this->sign($claims),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * The provider's RSA key pair, generated once per test run.
     *
     * @return array{private: string, public: string, jwk: array<string, string>}
     */
    private static function key(): array
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($resource === false) {
            throw new RuntimeException('Could not generate an RSA key.');
        }

        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);

        if (! is_string($private) || ! is_array($details)) {
            throw new RuntimeException('Could not read the RSA key.');
        }

        /** @var array{key: string, rsa: array{n: string, e: string}} $details */
        self::$key = [
            'private' => $private,
            'public' => $details['key'],
            'jwk' => [
                'kty' => 'RSA',
                'use' => 'sig',
                'n' => self::b64($details['rsa']['n']),
                'e' => self::b64($details['rsa']['e']),
            ],
        ];

        return self::$key;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
