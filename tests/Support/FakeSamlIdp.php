<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use DOMDocument;
use DOMElement;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * A SAML IdP in a test file: it writes its metadata and signs responses
 * with the committed TEST-ONLY key pair in tests/fixtures/saml.
 *
 * Every response is built as a string and signed at runtime, so each test
 * can bend exactly one thing (the audience, a time, a signature) and prove
 * the driver refuses it. Every host is example.test. Nothing here is a real
 * IdP, and the key is published in this repo: never trust it anywhere.
 */
final class FakeSamlIdp
{
    public const ENTITY_ID = 'https://idp.example.test/idp/shibboleth';

    public const SSO_URL = 'https://idp.example.test/idp/profile/SAML2/Redirect/SSO';

    public const SP_ENTITY_ID = 'https://app.example.test/login/saml/metadata';

    public const ACS_URL = 'https://app.example.test/login/saml/acs';

    public const SERVICE_URL = 'https://app.example.test/login';

    public const NS_SAMLP = 'urn:oasis:names:tc:SAML:2.0:protocol';

    public const NS_SAML = 'urn:oasis:names:tc:SAML:2.0:assertion';

    private const SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    private const SHA1 = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';

    private static int $sequence = 0;

    public function __construct(
        private readonly FakeClock $clock,
    ) {}

    public static function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/saml/' . $name;
    }

    public static function key(): string
    {
        return (string) file_get_contents(self::fixture('idp-test-only.key'));
    }

    public static function cert(): string
    {
        return (string) file_get_contents(self::fixture('idp-test-only.crt'));
    }

    /**
     * A key pair nobody trusts, made fresh for this process.
     *
     * @return array{key: string, cert: string}
     */
    public static function attackerKeyPair(): array
    {
        static $pair = null;

        if ($pair === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            assert($key !== false);
            $csr = openssl_csr_new(['commonName' => 'attacker.example.test'], $key, ['digest_alg' => 'sha256']);
            assert($csr !== false && $csr !== true);
            $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
            assert($x509 !== false);
            openssl_pkey_export($key, $keyPem);
            openssl_x509_export($x509, $certPem);
            $pair = ['key' => (string) $keyPem, 'cert' => (string) $certPem];
        }

        return $pair;
    }

    /**
     * The IdP's metadata XML (one EntityDescriptor).
     */
    public static function metadataXml(string $entityId = self::ENTITY_ID, string $ssoUrl = self::SSO_URL, ?string $cert = null): string
    {
        $certBody = self::certBody($cert ?? self::cert());

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" entityID="{$entityId}">
              <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
                <md:KeyDescriptor use="signing">
                  <ds:KeyInfo><ds:X509Data><ds:X509Certificate>{$certBody}</ds:X509Certificate></ds:X509Data></ds:KeyInfo>
                </md:KeyDescriptor>
                <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{$ssoUrl}"/>
                <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="{$ssoUrl}"/>
              </md:IDPSSODescriptor>
            </md:EntityDescriptor>
            XML;
    }

    /**
     * Write the metadata to a temp file and return its path.
     */
    public static function metadataFile(?string $xml = null): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'sesame-saml-md-');
        file_put_contents($file, $xml ?? self::metadataXml());

        return $file;
    }

    /**
     * A SAML Response, as the XML string (unsigned unless signed in
     * $o). Each option bends one part of an otherwise valid response.
     *
     * @param array<string, mixed> $o
     */
    public function responseXml(string $inResponseTo, array $o = []): string
    {
        $now = $this->clock->now()->getTimestamp();
        $issued = $now + (int) ($o['issued_offset'] ?? 0);
        $responseId = '_r' . bin2hex(random_bytes(16));
        $assertionId = (string) ($o['assertion_id'] ?? '_a' . bin2hex(random_bytes(16)) . (++self::$sequence));
        $issuer = (string) ($o['issuer'] ?? self::ENTITY_ID);
        $audience = (string) ($o['audience'] ?? self::SP_ENTITY_ID);
        $destination = (string) ($o['destination'] ?? self::ACS_URL);
        $recipient = (string) ($o['recipient'] ?? self::ACS_URL);
        $notBefore = self::time($now + (int) ($o['not_before_offset'] ?? -60));
        $notOnOrAfter = self::time($now + (int) ($o['not_on_or_after_offset'] ?? 300));
        $authnInstant = self::time($now + (int) ($o['authn_offset'] ?? -10));
        $classRef = (string) ($o['class_ref'] ?? 'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport');
        $inResponseToAttr = $inResponseTo === '' ? '' : ' InResponseTo="' . $inResponseTo . '"';
        $scdInResponseTo = $inResponseTo === '' ? '' : ' InResponseTo="' . $inResponseTo . '"';
        $issueInstant = self::time($issued);

        /** @var array<string, list<string>> $attributes */
        $attributes = $o['attributes'] ?? [
            'urn:oid:0.9.2342.19200300.100.1.1' => ['ab1234'],
            'urn:oid:0.9.2342.19200300.100.1.3' => ['ab1234@example.test'],
            'urn:oid:2.16.840.1.113730.3.1.241' => ['Ada Byron'],
            'urn:oid:1.3.6.1.4.1.5923.1.1.1.1' => ['staff', 'member'],
        ];

        $attributeXml = '';

        foreach ($attributes as $name => $values) {
            $attributeXml .= '<saml:Attribute Name="' . $name . '" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:uri">';

            foreach ($values as $value) {
                $attributeXml .= '<saml:AttributeValue>' . $value . '</saml:AttributeValue>';
            }

            $attributeXml .= '</saml:Attribute>';
        }

        $assertion = <<<XML
            <saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" xmlns:xs="http://www.w3.org/2001/XMLSchema" ID="{$assertionId}" Version="2.0" IssueInstant="{$issueInstant}"><saml:Issuer>{$issuer}</saml:Issuer><saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:2.0:nameid-format:transient">_t3st</saml:NameID><saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="{$notOnOrAfter}" Recipient="{$recipient}"{$scdInResponseTo}/></saml:SubjectConfirmation></saml:Subject><saml:Conditions NotBefore="{$notBefore}" NotOnOrAfter="{$notOnOrAfter}"><saml:AudienceRestriction><saml:Audience>{$audience}</saml:Audience></saml:AudienceRestriction></saml:Conditions><saml:AuthnStatement AuthnInstant="{$authnInstant}" SessionIndex="_s1"><saml:AuthnContext><saml:AuthnContextClassRef>{$classRef}</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement><saml:AttributeStatement>{$attributeXml}</saml:AttributeStatement></saml:Assertion>
            XML;

        $signAssertion = (bool) ($o['sign_assertion'] ?? true);
        $signKey = (string) ($o['key'] ?? self::key());
        $signCert = (string) ($o['cert'] ?? self::cert());
        $algorithm = ($o['sha1'] ?? false) === true ? self::SHA1 : self::SHA256;
        $digest = ($o['sha1'] ?? false) === true ? XMLSecurityDSig::SHA1 : XMLSecurityDSig::SHA256;

        if ($signAssertion) {
            $assertion = self::sign($assertion, $signKey, $signCert, $algorithm, $digest);
        }

        $assertion = preg_replace('/^<\?xml[^>]*>\s*/', '', $assertion) ?? $assertion;

        if (isset($o['tamper']) && is_callable($o['tamper'])) {
            $assertion = (string) $o['tamper']($assertion);
        }

        $extra = (string) ($o['extra_assertion'] ?? '');

        $response = <<<XML
            <samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$responseId}" Version="2.0" IssueInstant="{$issueInstant}" Destination="{$destination}"{$inResponseToAttr}><saml:Issuer>{$issuer}</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>{$assertion}{$extra}</samlp:Response>
            XML;

        if (($o['sign_response'] ?? false) === true) {
            $response = self::sign($response, (string) ($o['response_key'] ?? $signKey), (string) ($o['response_cert'] ?? $signCert), $algorithm, $digest);
        }

        return $response;
    }

    /**
     * A SAML Response, base64-encoded as the HTTP-POST binding sends it.
     *
     * @param array<string, mixed> $o
     */
    public function response(string $inResponseTo, array $o = []): string
    {
        return base64_encode($this->responseXml($inResponseTo, $o));
    }

    /**
     * Sign the root element of $xml, with the Signature after its Issuer,
     * as the SAML schema wants it.
     */
    public static function sign(string $xml, string $key, string $cert, string $algorithm = self::SHA256, string $digest = XMLSecurityDSig::SHA256): string
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $root = $dom->documentElement;
        assert($root instanceof DOMElement);

        $objKey = new XMLSecurityKey($algorithm, ['type' => 'private']);
        $objKey->loadKey($key, false);

        $dsig = new XMLSecurityDSig();
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReferenceList(
            [$root],
            $digest,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID', 'overwrite' => false],
        );
        $dsig->sign($objKey);
        $dsig->add509Cert($cert, true);

        $issuer = null;

        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'Issuer') {
                $issuer = $child;

                break;
            }
        }

        $dsig->insertSignature($root, $issuer?->nextSibling);

        return (string) $dom->saveXML($root);
    }

    /**
     * The AuthnRequest inside a Redirect-binding URL, decoded.
     *
     * @return array{xml: string, id: string, query: array<string, string>, raw_query: string}
     */
    public static function authnRequest(string $url): array
    {
        $rawQuery = (string) parse_url($url, PHP_URL_QUERY);
        parse_str($rawQuery, $query);
        /** @var array<string, string> $query */
        $xml = (string) gzinflate((string) base64_decode($query['SAMLRequest'] ?? '', true));
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $root = $dom->documentElement;
        assert($root instanceof DOMElement);

        return ['xml' => $xml, 'id' => $root->getAttribute('ID'), 'query' => $query, 'raw_query' => $rawQuery];
    }

    private static function time(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private static function certBody(string $pem): string
    {
        return (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }
}
