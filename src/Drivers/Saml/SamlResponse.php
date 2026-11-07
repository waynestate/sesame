<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Saml;

use DOMDocument;
use DOMElement;
use DOMXPath;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Utils;

/**
 * php-saml's Response, with the few reads SESAME needs on top.
 *
 * php-saml validates the message (schema, signatures and what they cover,
 * Destination, Audience, Issuer, InResponseTo, the time conditions and the
 * subject confirmation). Every value read here comes from the same parsed
 * document php-saml checked, and the assertion values come from the one
 * assertion its signature reference points at (php-saml's _queryAssertion),
 * so there is one parser and one reading of the message (S47).
 */
final class SamlResponse extends Response
{
    /**
     * The signature and digest algorithms SESAME accepts: the SHA-2 family
     * only. SHA-1 (and MD5) are refused even when php-saml would verify them.
     *
     * @var list<string>
     */
    public const ALLOWED_ALGORITHMS = [
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384',
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512',
        'http://www.w3.org/2001/04/xmlenc#sha256',
        'http://www.w3.org/2001/04/xmldsig-more#sha384',
        'http://www.w3.org/2001/04/xmlenc#sha512',
    ];

    /**
     * The InResponseTo on the Response element, or null when there is none
     * (an IdP-initiated, unsolicited response). Read before validation only
     * to find the pending request; php-saml then checks it matches.
     */
    public function inResponseTo(): ?string
    {
        $root = $this->document()->documentElement;

        if (! $root instanceof DOMElement || ! $root->hasAttribute('InResponseTo')) {
            return null;
        }

        $value = trim($root->getAttribute('InResponseTo'));

        return $value === '' ? null : $value;
    }

    /**
     * The signed assertion's IssueInstant, as a Unix time, or null.
     */
    public function issueInstant(): ?int
    {
        $node = $this->assertionNode('');

        return $node instanceof DOMElement ? self::time($node->getAttribute('IssueInstant')) : null;
    }

    /**
     * When the IdP says the user authenticated (AuthnStatement
     * AuthnInstant), as a Unix time, or null.
     */
    public function authnInstant(): ?int
    {
        $node = $this->assertionNode('/saml:AuthnStatement');

        return $node instanceof DOMElement ? self::time($node->getAttribute('AuthnInstant')) : null;
    }

    /**
     * The AuthnContextClassRef of the AuthnStatement, or null.
     */
    public function authnContextClassRef(): ?string
    {
        $node = $this->assertionNode('/saml:AuthnStatement/saml:AuthnContext/saml:AuthnContextClassRef');

        if ($node === null) {
            return null;
        }

        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * The assertion's Issuer, or null.
     */
    public function assertionIssuer(): ?string
    {
        $node = $this->assertionNode('/saml:Issuer');

        if ($node === null) {
            return null;
        }

        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * Every SignatureMethod and DigestMethod algorithm in the message (and in
     * the decrypted assertion, when it was encrypted).
     *
     * @return list<string>
     */
    public function signatureAlgorithms(): array
    {
        $algorithms = [];

        foreach ([$this->document(), $this->decrypted()] as $document) {
            if (! $document instanceof DOMDocument) {
                continue;
            }

            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('ds', Constants::NS_DS);
            $nodes = $xpath->query('//ds:Signature/ds:SignedInfo/ds:SignatureMethod/@Algorithm | //ds:Signature/ds:SignedInfo/ds:Reference/ds:DigestMethod/@Algorithm');

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                $algorithms[] = (string) $node->nodeValue;
            }
        }

        return $algorithms;
    }

    /**
     * One node of the signed assertion, or null.
     */
    private function assertionNode(string $path): ?\DOMNode
    {
        $nodes = $this->_queryAssertion($path);

        if ($nodes->length !== 1) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof \DOMNode ? $node : null;
    }

    private function document(): DOMDocument
    {
        return $this->document;
    }

    private function decrypted(): ?DOMDocument
    {
        return $this->encrypted === true ? $this->decryptedDocument : null;
    }

    private static function time(string $value): ?int
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            $time = Utils::parseSAML2Time($value);
        } catch (\Throwable) {
            return null;
        }

        return $time;
    }
}
