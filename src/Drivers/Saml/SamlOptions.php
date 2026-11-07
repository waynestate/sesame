<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Saml;

use SensitiveParameterValue;
use Waynestate\Sesame\Exception\InvalidConfig;

/**
 * The saml driver's settings, checked once at boot.
 *
 * Every key is read from the `saml` block of the config array. Certificates,
 * keys and IdP metadata are always given as file paths (or, for metadata, an
 * https URL), never inline: the files are read here, at boot, so a missing
 * or unreadable file stops the app before the first sign-in. The SP private
 * key is the one value that is never echoed: it is held wrapped in a
 * SensitiveParameterValue, which var_export, json_encode, var_dump, print_r
 * and array casts show as empty and serialize() refuses.
 */
final readonly class SamlOptions
{
    /**
     * The attribute map an app gets when it names none: SESAME's user shape
     * on the left, the SAML attribute names (the eduPerson and inetOrgPerson
     * OIDs a Shibboleth-style IdP releases, then their friendly names) on
     * the right. The first name that the assertion carries wins.
     *
     * @var array<non-empty-string, list<non-empty-string>>
     */
    public const DEFAULT_ATTRIBUTES = [
        'accessid' => ['urn:oid:0.9.2342.19200300.100.1.1', 'uid'],
        'email' => ['urn:oid:0.9.2342.19200300.100.1.3', 'mail'],
        'name' => ['urn:oid:2.16.840.1.113730.3.1.241', 'displayName'],
        'affiliations' => ['urn:oid:1.3.6.1.4.1.5923.1.1.1.1', 'eduPersonAffiliation'],
        'eppn' => ['urn:oid:1.3.6.1.4.1.5923.1.1.1.6', 'eduPersonPrincipalName'],
    ];

    /**
     * The authentication context classes that count as MFA by default: the
     * REFEDS MFA profile, which Shibboleth-style IdPs report.
     *
     * @var list<non-empty-string>
     */
    public const DEFAULT_MFA_CONTEXTS = ['https://refeds.org/profile/mfa'];

    /**
     * The largest IdP metadata document the driver reads, in bytes. A single
     * IdP's metadata is a few kilobytes; a federation aggregate is not what
     * this setting is for.
     */
    public const MAX_METADATA_BYTES = 2_097_152;

    private ?SensitiveParameterValue $spKey;

    /**
     * @param non-empty-string $spEntityId
     * @param non-empty-string $acsUrl
     * @param non-empty-string $serviceUrl
     * @param non-empty-string|null $idpMetadataUrl
     * @param non-empty-string|null $idpEntityId
     * @param array<non-empty-string, list<non-empty-string>> $attributes
     * @param non-empty-string $usernameAttribute
     * @param list<non-empty-string> $mfaContexts
     * @param list<non-empty-string> $authnContexts
     */
    public function __construct(
        public string $spEntityId,
        public string $acsUrl,
        public string $serviceUrl,
        public ?string $idpMetadataUrl = null,
        public ?string $idpMetadataXml = null,
        public ?string $idpEntityId = null,
        public ?string $idpCert = null,
        public ?string $spCert = null,
        #[\SensitiveParameter]
        ?string $spKey = null,
        public bool $signAuthnRequests = false,
        public bool $wantMessagesSigned = false,
        public array $attributes = self::DEFAULT_ATTRIBUTES,
        public string $usernameAttribute = 'accessid',
        public array $mfaContexts = self::DEFAULT_MFA_CONTEXTS,
        public bool $assumeMfa = false,
        public array $authnContexts = [],
        public int $leewaySeconds = 60,
        public int $maxAssertionAgeSeconds = 300,
        public int $metadataCacheTtlSeconds = 3600,
        public int $requestTtlSeconds = 600,
    ) {
        $this->spKey = $spKey === null ? null : new SensitiveParameterValue($spKey);
    }

    /**
     * The SP private key (PEM), for signing AuthnRequests and decrypting
     * encrypted assertions, or null when none is configured.
     */
    public function spKey(): ?string
    {
        $value = $this->spKey?->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * What var_dump and print_r show: everything but the key.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $values = get_object_vars($this);
        $values['spKey'] = $this->spKey === null ? null : '[redacted]';
        $values['idpMetadataXml'] = $this->idpMetadataXml === null ? null : '[' . strlen($this->idpMetadataXml) . ' bytes]';

        return $values;
    }

    /**
     * Build the options from the `saml` block of the config array.
     *
     * @param array<mixed> $saml
     */
    public static function fromArray(#[\SensitiveParameter] array $saml): self
    {
        $sp = self::block($saml, 'sp');
        $idp = self::block($saml, 'idp');

        $entityId = self::requiredString($sp, 'entity_id', 'saml.sp.entity_id', 'SESAME_SAML_SP_ENTITY_ID');
        self::assertEntityId($entityId, 'saml.sp.entity_id');

        $acsUrl = self::requiredString($sp, 'acs_url', 'saml.sp.acs_url', 'SESAME_SAML_SP_ACS_URL');
        self::assertUrl($acsUrl, 'saml.sp.acs_url', allowLocalhost: true);

        $serviceUrl = self::requiredString($saml, 'service_url', 'saml.service_url', 'SESAME_SAML_SERVICE_URL');
        self::assertUrl($serviceUrl, 'saml.service_url', allowLocalhost: true);

        $metadataUrl = self::optionalString($idp, 'metadata_url', 'saml.idp.metadata_url');
        $metadataFile = self::optionalString($idp, 'metadata_file', 'saml.idp.metadata_file');

        if (($metadataUrl === null) === ($metadataFile === null)) {
            throw new InvalidConfig('Set exactly one of SESAME_SAML_IDP_METADATA_URL (saml.idp.metadata_url) and SESAME_SAML_IDP_METADATA_FILE (saml.idp.metadata_file): where the IdP\'s metadata comes from.');
        }

        $metadataXml = null;

        if ($metadataUrl !== null) {
            self::assertUrl($metadataUrl, 'saml.idp.metadata_url', allowLocalhost: false);
        } else {
            $metadataXml = self::readFile((string) $metadataFile, 'saml.idp.metadata_file', self::MAX_METADATA_BYTES);
        }

        $idpEntityId = self::optionalString($idp, 'entity_id', 'saml.idp.entity_id');

        if ($idpEntityId !== null) {
            self::assertEntityId($idpEntityId, 'saml.idp.entity_id');
        }

        $idpCertFile = self::optionalString($idp, 'cert_file', 'saml.idp.cert_file');
        $idpCert = $idpCertFile === null ? null : self::certificate($idpCertFile, 'saml.idp.cert_file');

        $spCertFile = self::optionalString($sp, 'cert_file', 'saml.sp.cert_file');
        $spKeyFile = self::optionalString($sp, 'key_file', 'saml.sp.key_file');

        if (($spCertFile === null) !== ($spKeyFile === null)) {
            throw new InvalidConfig('Set both SESAME_SAML_SP_CERT_FILE (saml.sp.cert_file) and SESAME_SAML_SP_KEY_FILE (saml.sp.key_file), or neither: the SP certificate and its private key go together.');
        }

        $spCert = $spCertFile === null ? null : self::certificate($spCertFile, 'saml.sp.cert_file');
        $spKey = $spKeyFile === null ? null : self::privateKey($spKeyFile, $spCert);

        $signAuthnRequests = self::bool($saml, 'sign_authn_requests', false);

        if ($signAuthnRequests && $spKey === null) {
            throw new InvalidConfig('SESAME_SAML_SIGN_AUTHN_REQUESTS (saml.sign_authn_requests) needs the SP key pair: set SESAME_SAML_SP_CERT_FILE and SESAME_SAML_SP_KEY_FILE.');
        }

        $attributes = self::attributes($saml['attributes'] ?? null);
        $usernameAttribute = self::optionalString($saml, 'username_attribute', 'saml.username_attribute') ?? 'accessid';

        if (! isset($attributes[$usernameAttribute])) {
            throw new InvalidConfig(sprintf(
                'The config key "saml.username_attribute" names "%s", which is not a key of "saml.attributes".',
                $usernameAttribute,
            ));
        }

        return new self(
            $entityId,
            $acsUrl,
            $serviceUrl,
            $metadataUrl,
            $metadataXml,
            $idpEntityId,
            $idpCert,
            $spCert,
            $spKey,
            $signAuthnRequests,
            self::bool($saml, 'want_messages_signed', false),
            $attributes,
            $usernameAttribute,
            self::list(in_array($saml['mfa_contexts'] ?? null, [null, ''], true) ? self::DEFAULT_MFA_CONTEXTS : $saml['mfa_contexts'], 'saml.mfa_contexts'),
            self::bool($saml, 'assume_mfa', false),
            self::list($saml['authn_context'] ?? [], 'saml.authn_context'),
            // Never looser than php-saml's own fixed clock drift (180
            // seconds), which it applies to the conditions it checks.
            self::int($saml, 'leeway_seconds', 60, 0, 180),
            self::int($saml, 'max_assertion_age_seconds', 300, 30, 3600),
            self::int($saml, 'metadata_cache_ttl_seconds', 3600, 60, 86400),
            self::int($saml, 'request_ttl_seconds', 600, 60, 3600),
        );
    }

    /**
     * @param array<mixed> $saml
     * @return array<mixed>
     */
    private static function block(array $saml, string $key): array
    {
        $value = $saml[$key] ?? [];

        if (! is_array($value)) {
            throw new InvalidConfig(sprintf('The config key "saml.%s" must be an array.', $key));
        }

        return $value;
    }

    /**
     * The attribute map: SESAME's name to a list of SAML attribute names (or
     * one name, or a comma-separated string of names).
     *
     * @return array<non-empty-string, list<non-empty-string>>
     */
    private static function attributes(mixed $value): array
    {
        if ($value === null) {
            return self::DEFAULT_ATTRIBUTES;
        }

        if (! is_array($value) || $value === []) {
            throw new InvalidConfig('The config key "saml.attributes" must be a non-empty map of attribute name to SAML attribute names.');
        }

        $map = [];

        foreach ($value as $name => $sources) {
            if (! is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $name) !== 1) {
                throw new InvalidConfig('Each key of "saml.attributes" must be a short attribute name (letters, digits, "_", "." or "-").');
            }

            // An entry the environment left empty keeps the default for that
            // name, so SESAME_SAML_ATTRIBUTE_EMAIL unset still maps email.
            if ($sources === null || $sources === '' || $sources === []) {
                if (isset(self::DEFAULT_ATTRIBUTES[$name])) {
                    $map[$name] = self::DEFAULT_ATTRIBUTES[$name];
                }

                continue;
            }

            $map[$name] = self::list($sources, 'saml.attributes.' . $name);
        }

        if ($map === []) {
            throw new InvalidConfig('The config key "saml.attributes" maps no attribute.');
        }

        return $map;
    }

    /**
     * A list of non-empty strings without whitespace, from a list or a
     * comma-separated string.
     *
     * @return list<non-empty-string>
     */
    private static function list(mixed $value, string $path): array
    {
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        }

        if (! is_array($value)) {
            throw new InvalidConfig(sprintf('The config key "%s" must be a comma-separated string or a list of strings.', $path));
        }

        $list = [];

        foreach ($value as $item) {
            if (! is_string($item) || preg_match('/^[!-~]{1,1024}$/D', $item) !== 1) {
                throw new InvalidConfig(sprintf('Each entry in "%s" must be a non-empty string without spaces.', $path));
            }

            if (! in_array($item, $list, true)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * An entity ID: a URI or URN without whitespace or quotes, at most 1024
     * characters (SAML 2.0 Core 8.3.6).
     */
    private static function assertEntityId(string $value, string $path): void
    {
        if (preg_match('/^[!#-&(-~]{1,1024}$/D', $value) !== 1) {
            throw new InvalidConfig(sprintf('The config key "%s" must be an entity ID (a URI) without spaces or quotes.', $path));
        }
    }

    /**
     * The URL must be an absolute https:// URL with a host, no credentials,
     * no query and no fragment. With $allowLocalhost, http://localhost and
     * http://127.0.0.1 are accepted too, for local development.
     */
    private static function assertUrl(string $url, string $path, bool $allowLocalhost): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';

        $ok = is_array($parts)
            && $host !== ''
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts)
            && preg_match('/[\x00-\x20\x7F"<>\\\\]/', $url) !== 1
            && ($scheme === 'https'
                || ($allowLocalhost && $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true)));

        if (! $ok) {
            throw new InvalidConfig(sprintf(
                'The config key "%s" must be an absolute https:// URL with no credentials, query or fragment.',
                $path,
            ));
        }
    }

    /**
     * Read a file named by config, at most $maxBytes long.
     */
    private static function readFile(string $file, string $path, int $maxBytes): string
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw new InvalidConfig(sprintf('The file named by "%s" does not exist or is not readable.', $path));
        }

        $size = filesize($file);

        if ($size === false || $size === 0 || $size > $maxBytes) {
            throw new InvalidConfig(sprintf('The file named by "%s" is empty or larger than %d bytes.', $path, $maxBytes));
        }

        $contents = file_get_contents($file);

        if (! is_string($contents) || $contents === '') {
            throw new InvalidConfig(sprintf('The file named by "%s" could not be read.', $path));
        }

        return $contents;
    }

    /**
     * A PEM certificate from a file, checked with openssl.
     */
    private static function certificate(string $file, string $path): string
    {
        $pem = self::readFile($file, $path, 65536);

        // The marker check first: openssl warns on input that is not a
        // certificate at all.
        if (! str_contains($pem, '-----BEGIN CERTIFICATE-----') || openssl_x509_read($pem) === false) {
            throw new InvalidConfig(sprintf('The file named by "%s" is not a PEM X.509 certificate.', $path));
        }

        return $pem;
    }

    /**
     * The SP private key from a file: a PEM RSA key that matches the SP
     * certificate. The key's contents never reach an error message.
     */
    private static function privateKey(string $file, ?string $certificate): string
    {
        $pem = self::readFile($file, 'saml.sp.key_file', 65536);
        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new InvalidConfig('The file named by "saml.sp.key_file" is not an unencrypted PEM private key.');
        }

        if ($certificate !== null && ! openssl_x509_check_private_key($certificate, $key)) {
            throw new InvalidConfig('The SP private key (saml.sp.key_file) does not match the SP certificate (saml.sp.cert_file).');
        }

        return $pem;
    }

    /**
     * @param array<mixed> $values
     * @return non-empty-string
     */
    private static function requiredString(array $values, string $key, string $path, string $setting): string
    {
        $value = $values[$key] ?? null;

        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            throw new InvalidConfig(sprintf('Missing required setting %s (%s).', $setting, $path));
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     * @return non-empty-string|null
     */
    private static function optionalString(array $values, string $key, string $path): ?string
    {
        $value = $values[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            throw new InvalidConfig(sprintf('The config key "%s" must be a non-empty string.', $path));
        }

        return $value;
    }

    /**
     * An integer within bounds. Laravel's env() hands numbers over as
     * strings, so a string of digits is accepted too.
     *
     * @param array<mixed> $saml
     */
    private static function int(array $saml, string $key, int $default, int $min, int $max): int
    {
        $value = $saml[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidConfig(sprintf('The config key "saml.%s" must be an integer from %d to %d.', $key, $min, $max));
        }

        return $value;
    }

    /**
     * A boolean. Laravel's env() turns "true" and "false" into booleans
     * already; "1" and "0" are accepted too.
     *
     * @param array<mixed> $saml
     */
    private static function bool(array $saml, string $key, bool $default): bool
    {
        $value = $saml[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if ($value === '1' || $value === 1) {
            return true;
        }

        if ($value === '0' || $value === 0) {
            return false;
        }

        if (! is_bool($value)) {
            throw new InvalidConfig(sprintf('The config key "saml.%s" must be a boolean.', $key));
        }

        return $value;
    }
}
