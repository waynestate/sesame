<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Drivers\Saml\SamlOptions;
use Waynestate\Sesame\Drivers\SamlDriver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Tests\Support\ArrayCache;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\FakeHttpClient;
use Waynestate\Sesame\Tests\Support\FakeSamlIdp;
use Waynestate\Sesame\Tests\Support\RecordingSession;

/**
 * The saml driver, end to end against an in-process IdP.
 *
 * FakeSamlIdp signs every response at runtime with the committed TEST-ONLY
 * key in tests/fixtures/saml. Each negative test bends one thing in an
 * otherwise valid response and proves the driver refuses it. php-saml
 * checks the time conditions against the real clock, so the test clock
 * starts at the real time.
 */
final class SamlDriverTest extends TestCase
{
    private FakeClock $clock;

    private FakeSamlIdp $idp;

    private ArrayCache $cache;

    private RecordingSession $session;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->clock = new FakeClock('@' . time());
        $this->idp = new FakeSamlIdp($this->clock);
        $this->cache = new ArrayCache($this->clock);
        $this->session = new RecordingSession(new EventLog());
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    public function testNameAndUrls(): void
    {
        $driver = $this->driver();

        self::assertSame('saml', $driver->name());
        self::assertSame(FakeSamlIdp::SERVICE_URL, $driver->serviceUrl());
        self::assertSame(FakeSamlIdp::ACS_URL, $driver->acsUrl());
        self::assertNull($driver->logoutUrl('https://app.example.test/'));
    }

    public function testTheHappyPathSignsTheUserInAndMapsTheAttributes(): void
    {
        $driver = $this->driver();

        $step = $this->signIn($driver);
        $identity = $step->identity;

        self::assertSame('ab1234', $identity->username);
        self::assertSame('saml', $identity->driver);
        self::assertSame(['ab1234'], $identity->attributes['accessid']);
        self::assertSame(['ab1234@example.test'], $identity->attributes['email']);
        self::assertSame(['Ada Byron'], $identity->attributes['name']);
        self::assertSame(['staff', 'member'], $identity->attributes['affiliations']);
        self::assertSame([FakeSamlIdp::ENTITY_ID], $identity->attributes['issuer']);
        self::assertSame([], $identity->amr);
        self::assertNotNull($identity->authTime);
        self::assertSame($this->clock->now()->getTimestamp() - 10, $identity->authTime->getTimestamp());
        self::assertSame($identity->authTime, $identity->authenticatedAt);
    }

    public function testFriendlyNamesMapWhenTheIdpSendsNoOids(): void
    {
        $step = $this->signIn($this->driver(), [
            'attributes' => ['uid' => ['cd5678'], 'mail' => ['cd5678@example.test']],
        ]);

        self::assertSame('cd5678', $step->identity->username);
        self::assertSame(['cd5678@example.test'], $step->identity->attributes['email']);
        self::assertArrayNotHasKey('name', $step->identity->attributes);
    }

    public function testACustomAttributeMapAndUsernameAttribute(): void
    {
        $driver = $this->driver([
            'attributes' => ['eppn' => 'urn:oid:1.3.6.1.4.1.5923.1.1.1.6', 'accessid' => ''],
            'username_attribute' => 'eppn',
        ]);

        $step = $this->signIn($driver, [
            'attributes' => [
                'urn:oid:1.3.6.1.4.1.5923.1.1.1.6' => ['ab1234@example.test'],
                'urn:oid:0.9.2342.19200300.100.1.1' => ['ab1234'],
            ],
        ]);

        self::assertSame('ab1234@example.test', $step->identity->username);
        self::assertSame(['ab1234'], $step->identity->attributes['accessid']);
    }

    public function testAnMfaContextGivesAnMfaAmrAndAnAuthContext(): void
    {
        $step = $this->signIn($this->driver(), ['class_ref' => 'https://refeds.org/profile/mfa']);

        self::assertSame(['mfa'], $step->identity->amr);
        self::assertTrue($step->identity->hasAuthContext('https://refeds.org/profile/mfa'));
    }

    public function testAssumeMfa(): void
    {
        $step = $this->signIn($this->driver(['assume_mfa' => true]));

        self::assertSame(['mfa'], $step->identity->amr);
    }

    public function testAMissingUsernameAttributeIsRefusedWithAHint(): void
    {
        $this->expectFailure($this->driver(), ['attributes' => ['mail' => ['x@example.test']]], 'saml.missing_attribute');
    }

    public function testTheStartRedirectCarriesADeflatedAuthnRequest(): void
    {
        $driver = $this->driver();

        $redirect = $driver->login(new ServerRequest('GET', FakeSamlIdp::SERVICE_URL));

        self::assertInstanceOf(Redirect::class, $redirect);
        self::assertStringStartsWith(FakeSamlIdp::SSO_URL . '?SAMLRequest=', $redirect->url);

        $request = FakeSamlIdp::authnRequest($redirect->url);

        self::assertStringContainsString('AssertionConsumerServiceURL="' . FakeSamlIdp::ACS_URL . '"', $request['xml']);
        self::assertStringContainsString('Destination="' . FakeSamlIdp::SSO_URL . '"', $request['xml']);
        self::assertStringContainsString(FakeSamlIdp::SP_ENTITY_ID, $request['xml']);
        self::assertStringNotContainsString('ForceAuthn="true"', $request['xml']);
        self::assertStringNotContainsString('RequestedAuthnContext', $request['xml']);
        self::assertArrayNotHasKey('RelayState', $request['query']);
        self::assertArrayNotHasKey('Signature', $request['query']);
        self::assertIsArray($this->session->store['sesame.saml.pending'] ?? null);
    }

    public function testASafeReturnToRidesAsTheRelayStateAndComesBack(): void
    {
        $driver = $this->driver();
        $returnTo = 'https://app.example.test/reports?id=7';

        $redirect = $driver->login(new ServerRequest('GET', FakeSamlIdp::SERVICE_URL . '?return_to=' . urlencode($returnTo)));
        self::assertInstanceOf(Redirect::class, $redirect);
        $request = FakeSamlIdp::authnRequest($redirect->url);

        self::assertSame($returnTo, $request['query']['RelayState']);

        $step = $this->finish($driver, $request['id'], [], $returnTo);

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame($returnTo, $driver->returnTo());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeReturnTos(): iterable
    {
        yield 'another host' => ['https://evil.example.test/'];
        yield 'http' => ['http://app.example.test/'];
        yield 'credentials' => ['https://user:pass@app.example.test/'];
        yield 'relative' => ['/reports'];
        yield 'protocol relative' => ['//evil.example.test/'];
    }

    #[DataProvider('unsafeReturnTos')]
    public function testAnUnsafeReturnToIsNeverSentNorHonored(string $returnTo): void
    {
        $driver = $this->driver();

        $redirect = $driver->login(new ServerRequest('GET', FakeSamlIdp::SERVICE_URL . '?return_to=' . urlencode($returnTo)));
        self::assertInstanceOf(Redirect::class, $redirect);
        $request = FakeSamlIdp::authnRequest($redirect->url);

        self::assertArrayNotHasKey('RelayState', $request['query']);

        // A RelayState the IdP (or an attacker) sends back is checked again.
        $step = $this->finish($driver, $request['id'], [], $returnTo);

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertNull($driver->returnTo());
    }

    public function testASignedAuthnRequestVerifiesWithTheSpCertificate(): void
    {
        $driver = $this->driver([
            'sp' => ['cert_file' => FakeSamlIdp::fixture('sp-test-only.crt'), 'key_file' => FakeSamlIdp::fixture('sp-test-only.key')],
            'sign_authn_requests' => true,
        ]);

        $redirect = $driver->login(new ServerRequest('GET', FakeSamlIdp::SERVICE_URL . '?return_to=' . urlencode('https://app.example.test/x')));
        self::assertInstanceOf(Redirect::class, $redirect);
        $request = FakeSamlIdp::authnRequest($redirect->url);

        self::assertSame(XMLSecurityKey::RSA_SHA256, $request['query']['SigAlg']);

        $signed = 'SAMLRequest=' . urlencode($request['query']['SAMLRequest'])
            . '&RelayState=' . urlencode($request['query']['RelayState'])
            . '&SigAlg=' . urlencode($request['query']['SigAlg']);

        self::assertSame(1, openssl_verify(
            $signed,
            (string) base64_decode($request['query']['Signature'], true),
            (string) file_get_contents(FakeSamlIdp::fixture('sp-test-only.crt')),
            OPENSSL_ALGO_SHA256,
        ));
    }

    public function testStepUpSendsForceAuthnAndAcceptsAFreshSignIn(): void
    {
        $driver = $this->driver();

        $redirect = $driver->login(new ServerRequest('GET', FakeSamlIdp::SERVICE_URL . '?step_up=1'));
        self::assertInstanceOf(Redirect::class, $redirect);
        $request = FakeSamlIdp::authnRequest($redirect->url);

        self::assertStringContainsString('ForceAuthn="true"', $request['xml']);
        self::assertInstanceOf(Authenticated::class, $this->finish($driver, $request['id'], ['authn_offset' => -1]));
    }

    public function testStepUpRefusesAnEarlierSignIn(): void
    {
        $driver = $this->driver();

        $redirect = $driver->login(new ServerRequest('GET', FakeSamlIdp::SERVICE_URL . '?step_up=1'));
        self::assertInstanceOf(Redirect::class, $redirect);
        $request = FakeSamlIdp::authnRequest($redirect->url);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('saml.step_up');

        $this->finish($driver, $request['id'], ['authn_offset' => -3600]);
    }

    public function testReceiveParksTheResponseAndRedirectsToTheLoginUrl(): void
    {
        $driver = $this->driver();
        $id = $this->start($driver);
        $before = $this->session->store;

        $redirect = $driver->receive($this->acsPost($this->idp->response($id)));

        self::assertMatchesRegularExpression('#^https://app\.example\.test/login\?saml_handle=[A-Za-z0-9_-]{43}$#D', $redirect->url);
        self::assertSame($before, $this->session->store, 'The ACS must not touch the session.');
    }

    public function testReceiveRefusesAGetAndAnEmptyOrOddBody(): void
    {
        $driver = $this->driver();

        foreach ([
            new ServerRequest('GET', FakeSamlIdp::ACS_URL),
            $this->acsPost(''),
            $this->acsPost('not base64 <xml>'),
            $this->acsPost(str_repeat('A', SamlDriver::MAX_RESPONSE_BYTES + 4)),
        ] as $request) {
            try {
                $driver->receive($request);
                self::fail('The ACS accepted an unusable request.');
            } catch (AuthenticationFailed $e) {
                self::assertStringContainsString('saml.acs', $e->getMessage());
            }
        }
    }

    public function testAHandleIsSingleUse(): void
    {
        $driver = $this->driver();
        $id = $this->start($driver);
        $redirect = $driver->receive($this->acsPost($this->idp->response($id)));

        self::assertInstanceOf(Authenticated::class, $driver->login(new ServerRequest('GET', $redirect->url)));

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('saml.handle');

        $driver->login(new ServerRequest('GET', $redirect->url));
    }

    public function testAnUnknownOrMalformedHandleIsRefused(): void
    {
        $driver = $this->driver();

        foreach (['nope', str_repeat('a', 43)] as $handle) {
            try {
                $driver->login(new ServerRequest('GET', FakeSamlIdp::SERVICE_URL . '?saml_handle=' . $handle));
                self::fail('An unknown handle was accepted.');
            } catch (AuthenticationFailed $e) {
                self::assertStringContainsString('saml.handle', $e->getMessage());
            }
        }
    }

    public function testAParkedResponseExpires(): void
    {
        $driver = $this->driver();
        $id = $this->start($driver);
        $redirect = $driver->receive($this->acsPost($this->idp->response($id)));

        $this->clock->advance(121);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('saml.handle');

        $driver->login(new ServerRequest('GET', $redirect->url));
    }

    public function testAnUnsignedAssertionIsRefused(): void
    {
        $this->expectFailure($this->driver(), ['sign_assertion' => false], 'saml.invalid: no_signed_assertion');
    }

    public function testASignedResponseWithAnUnsignedAssertionIsRefused(): void
    {
        // The assertion signature is required even when the whole message
        // is signed (wantAssertionsSigned).
        $this->expectFailure($this->driver(), ['sign_assertion' => false, 'sign_response' => true], 'saml.invalid: no_signed_assertion');
    }

    public function testASignedResponseAndSignedAssertionAreAccepted(): void
    {
        self::assertInstanceOf(Authenticated::class, $this->signIn($this->driver(['want_messages_signed' => true]), ['sign_response' => true]));
    }

    public function testWantMessagesSignedRefusesAnUnsignedResponse(): void
    {
        $this->expectFailure($this->driver(['want_messages_signed' => true]), [], 'saml.invalid: no_signed_message');
    }

    public function testAnAssertionSignedByAnotherKeyIsRefused(): void
    {
        $pair = FakeSamlIdp::attackerKeyPair();

        $this->expectFailure($this->driver(), ['key' => $pair['key'], 'cert' => $pair['cert']], 'saml.invalid: invalid_signature');
    }

    public function testATamperedAssertionIsRefused(): void
    {
        $this->expectFailure($this->driver(), [
            'tamper' => static fn(string $xml): string => str_replace('>ab1234<', '>zz9999<', $xml),
        ], 'saml.invalid');
    }

    public function testAWrongAudienceIsRefused(): void
    {
        $this->expectFailure($this->driver(), ['audience' => 'https://other.example.test/sp'], 'saml.invalid: wrong_audience');
    }

    public function testAWrongIssuerIsRefused(): void
    {
        $this->expectFailure($this->driver(), ['issuer' => 'https://evil.example.test/idp'], 'saml.invalid: wrong_issuer');
    }

    public function testAWrongDestinationIsRefused(): void
    {
        $this->expectFailure($this->driver(), ['destination' => 'https://other.example.test/acs'], 'saml.invalid: wrong_destination');
    }

    public function testAWrongRecipientIsRefused(): void
    {
        $this->expectFailure($this->driver(), ['recipient' => 'https://other.example.test/acs'], 'saml.invalid: wrong_subjectconfirmation');
    }

    public function testAnExpiredAssertionIsRefused(): void
    {
        $this->expectFailure($this->driver(), ['not_on_or_after_offset' => -600, 'not_before_offset' => -900], 'saml.invalid: assertion_expired');
    }

    public function testAnAssertionNotYetValidIsRefused(): void
    {
        $this->expectFailure($this->driver(), ['not_before_offset' => 900, 'not_on_or_after_offset' => 1200], 'saml.invalid: assertion_too_early');
    }

    public function testAnAssertionIssuedInTheFutureIsRefused(): void
    {
        // Inside php-saml's fixed 180 s drift, but beyond SESAME's leeway.
        $this->expectFailure($this->driver(['leeway_seconds' => 30]), ['issued_offset' => 120], 'saml.issue_instant');
    }

    public function testAnOldAssertionIsRefused(): void
    {
        $this->expectFailure($this->driver(['max_assertion_age_seconds' => 60, 'leeway_seconds' => 0]), ['issued_offset' => -120], 'saml.too_old');
    }

    public function testAReplayedResponseIsRefused(): void
    {
        $driver = $this->driver();
        $id = $this->start($driver);
        $response = $this->idp->response($id);

        $redirect = $driver->receive($this->acsPost($response));
        self::assertInstanceOf(Authenticated::class, $driver->login(new ServerRequest('GET', $redirect->url)));

        // The same POST again: the request it answers is spent.
        $redirect = $driver->receive($this->acsPost($response));

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('saml.in_response_to');

        $driver->login(new ServerRequest('GET', $redirect->url));
    }

    public function testAReplayedAssertionIdIsRefusedEvenForANewRequest(): void
    {
        $driver = $this->driver();

        self::assertInstanceOf(Authenticated::class, $this->signIn($driver, ['assertion_id' => '_replayed1']));

        $this->expectFailure($driver, ['assertion_id' => '_replayed1'], 'saml.replay');
    }

    public function testAResponseToAnotherBrowsersRequestIsRefused(): void
    {
        $driver = $this->driver();
        $this->start($driver);

        // Login CSRF: a response the attacker got for their own request.
        $this->expectFailure($driver, [], 'saml.in_response_to', inResponseTo: '_attackers' . bin2hex(random_bytes(8)));
    }

    public function testAnUnsolicitedResponseIsRefused(): void
    {
        $this->expectFailure($this->driver(), [], 'saml.unsolicited', inResponseTo: '');
    }

    public function testAnExpiredPendingRequestIsRefused(): void
    {
        $driver = $this->driver(['request_ttl_seconds' => 60]);
        $id = $this->start($driver);
        $this->clock->advance(61);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('saml.in_response_to');

        $this->finish($driver, $id, ['issued_offset' => 0]);
    }

    public function testASha1SignatureIsRefused(): void
    {
        $this->expectFailure($this->driver(), ['sha1' => true], 'saml.algorithm');
    }

    public function testADoctypeIsRefused(): void
    {
        $driver = $this->driver();
        $id = $this->start($driver);
        $xml = $this->idp->responseXml($id);
        $evil = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x "ab1234">]>' . $xml;

        $redirect = $driver->receive($this->acsPost(base64_encode($evil)));

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('saml.response');

        $driver->login(new ServerRequest('GET', $redirect->url));
    }

    public function testASecondAssertionIsRefused(): void
    {
        $driver = $this->driver();
        $id = $this->start($driver);

        // Wrapping: a valid signed assertion plus an unsigned one naming
        // someone else.
        $evil = str_replace(
            ['ab1234', 'ID="_a'],
            ['root', 'ID="_evil'],
            (string) preg_replace('#<ds:Signature.*</ds:Signature>#s', '', (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $this->idp->responseXml($id, ['sign_assertion' => false]))),
        );
        self::assertSame(1, preg_match('#<saml:Assertion.*</saml:Assertion>#s', $evil, $match));

        $this->expectFailureFor($driver, $id, ['extra_assertion' => $match[0] ?? ''], 'saml.invalid: wrong_number_of_assertions');
    }

    public function testAnAssertionInALookalikeNamespaceIsRefused(): void
    {
        $driver = $this->driver();
        $id = $this->start($driver);
        $extra = '<x:Assertion xmlns:x="urn:oasis:names:tc:SAML:2.0:assertion:evil" ID="_x1"><x:Issuer>' . FakeSamlIdp::ENTITY_ID . '</x:Issuer></x:Assertion>';

        $this->expectFailureFor($driver, $id, ['extra_assertion' => $extra], 'saml.invalid');
    }

    public function testASecondSignatureInTheAssertionIsRefused(): void
    {
        $pair = FakeSamlIdp::attackerKeyPair();
        $driver = $this->driver();
        $id = $this->start($driver);

        $this->expectFailureFor($driver, $id, [
            'tamper' => static function (string $xml) use ($pair): string {
                // A second, attacker-made signature over the same assertion.
                $second = FakeSamlIdp::sign($xml, $pair['key'], $pair['cert']);
                preg_match_all('#<ds:Signature\b.*?</ds:Signature>#s', $second, $sigs);

                return str_replace('</saml:Issuer>', '</saml:Issuer>' . ($sigs[0][0] ?? ''), $xml);
            },
        ], 'saml.invalid: duplicated_id_in_signed_elements');
    }

    public function testACommentInASignedValueDoesNotTruncateIt(): void
    {
        // Exclusive C14N drops comments, so a comment inside a signed value
        // keeps the signature valid; the value must still read whole.
        $step = $this->signIn($this->driver(), [
            'tamper' => static fn(string $xml): string => str_replace('>ab1234<', '>ab12<!-- x -->34<', $xml),
        ]);

        self::assertSame('ab1234', $step->identity->username);
    }

    public function testTheSpMetadataAsksForSignedAssertions(): void
    {
        $xml = $this->driver([
            'sp' => ['cert_file' => FakeSamlIdp::fixture('sp-test-only.crt'), 'key_file' => FakeSamlIdp::fixture('sp-test-only.key')],
        ])->metadata();

        self::assertStringContainsString('entityID="' . FakeSamlIdp::SP_ENTITY_ID . '"', $xml);
        self::assertStringContainsString('WantAssertionsSigned="true"', $xml);
        self::assertStringContainsString('Location="' . FakeSamlIdp::ACS_URL . '"', $xml);
        self::assertStringContainsString('urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST', $xml);
        self::assertStringContainsString('X509Certificate', $xml);
        self::assertStringNotContainsString('PRIVATE KEY', $xml);
    }

    public function testMetadataFromAUrlIsFetchedOnceAndCached(): void
    {
        $http = new FakeHttpClient([new Response(200, ['Content-Type' => 'application/samlmetadata+xml'], FakeSamlIdp::metadataXml())]);

        $options = $this->options(['idp' => ['metadata_url' => 'https://idp.example.test/idp/shibboleth', 'metadata_file' => null]]);
        $driver = new SamlDriver($options, $this->session, $this->cache, null, $http, new Psr17Factory(), $this->clock);

        self::assertInstanceOf(Authenticated::class, $this->signIn($driver));

        $again = new SamlDriver($options, $this->session, $this->cache, null, $http, new Psr17Factory(), $this->clock);
        self::assertInstanceOf(Redirect::class, $again->login(new ServerRequest('GET', FakeSamlIdp::SERVICE_URL)));
        self::assertCount(1, $http->requests);
        self::assertSame('https://idp.example.test/idp/shibboleth', (string) $http->requests[0]->getUri());
    }

    public function testMetadataThatIsNotA200IsRefused(): void
    {
        $http = new FakeHttpClient([new Response(302, ['Location' => 'https://evil.example.test/'])]);
        $options = $this->options(['idp' => ['metadata_url' => 'https://idp.example.test/idp/shibboleth', 'metadata_file' => null]]);
        $driver = new SamlDriver($options, $this->session, $this->cache, null, $http, new Psr17Factory(), $this->clock);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('saml.metadata');

        $driver->login(new ServerRequest('GET', FakeSamlIdp::SERVICE_URL));
    }

    public function testAPinnedCertificateOverridesTheMetadata(): void
    {
        $pair = FakeSamlIdp::attackerKeyPair();
        $certFile = $this->temp($pair['cert']);

        // The metadata names the fixture IdP's cert, but the app pinned
        // another one: the real IdP's signature no longer verifies.
        $this->expectFailure($this->driver(['idp' => ['cert_file' => $certFile]]), [], 'saml.invalid');
    }

    public function testMetadataWithTwoIdpsNeedsAnEntityId(): void
    {
        $two = str_replace(
            ['<?xml version="1.0" encoding="UTF-8"?>', '<md:EntityDescriptor ', '</md:EntityDescriptor>'],
            ['', '<md:EntityDescriptor ', '</md:EntityDescriptor>'],
            FakeSamlIdp::metadataXml(),
        );
        $other = str_replace(FakeSamlIdp::ENTITY_ID, 'https://other.example.test/idp', $two);
        $xml = '<?xml version="1.0"?><md:EntitiesDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata">' . $two . $other . '</md:EntitiesDescriptor>';
        $file = $this->temp($xml);

        try {
            $this->driver(['idp' => ['metadata_file' => $file]]);
            self::fail('Two IdPs without an entity ID were accepted.');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString('more than one IdP', $e->getMessage());
        }

        self::assertInstanceOf(Authenticated::class, $this->signIn($this->driver(['idp' => ['metadata_file' => $file, 'entity_id' => FakeSamlIdp::ENTITY_ID]])));
    }

    public function testADebugDumpNeverShowsTheSpKey(): void
    {
        $driver = $this->driver([
            'sp' => ['cert_file' => FakeSamlIdp::fixture('sp-test-only.crt'), 'key_file' => FakeSamlIdp::fixture('sp-test-only.key')],
        ]);

        self::assertStringNotContainsString('PRIVATE KEY', print_r($driver, true));
        self::assertStringNotContainsString('PRIVATE KEY', var_export((array) $driver, true));
    }

    /**
     * Options for the fixture IdP, with $overrides merged in.
     *
     * @param array<string, mixed> $overrides
     */
    private function options(array $overrides = []): SamlOptions
    {
        $config = [
            'sp' => ['entity_id' => FakeSamlIdp::SP_ENTITY_ID, 'acs_url' => FakeSamlIdp::ACS_URL],
            'idp' => ['metadata_file' => $this->temp(FakeSamlIdp::metadataXml())],
            'service_url' => FakeSamlIdp::SERVICE_URL,
        ];

        foreach ($overrides as $key => $value) {
            $config[$key] = is_array($value) && is_array($config[$key] ?? null) ? array_merge($config[$key], $value) : $value;
        }

        return SamlOptions::fromArray($config);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function driver(array $overrides = []): SamlDriver
    {
        return new SamlDriver($this->options($overrides), $this->session, $this->cache, null, null, null, $this->clock);
    }

    private function start(SamlDriver $driver): string
    {
        $redirect = $driver->login(new ServerRequest('GET', FakeSamlIdp::SERVICE_URL));
        self::assertInstanceOf(Redirect::class, $redirect);

        return FakeSamlIdp::authnRequest($redirect->url)['id'];
    }

    /**
     * @param array<string, mixed> $o
     */
    private function finish(SamlDriver $driver, string $inResponseTo, array $o = [], ?string $relayState = null): mixed
    {
        $redirect = $driver->receive($this->acsPost($this->idp->response($inResponseTo, $o), $relayState));

        return $driver->login(new ServerRequest('GET', $redirect->url));
    }

    /**
     * @param array<string, mixed> $o
     */
    private function signIn(SamlDriver $driver, array $o = []): Authenticated
    {
        $step = $this->finish($driver, $this->start($driver), $o);
        self::assertInstanceOf(Authenticated::class, $step);

        return $step;
    }

    /**
     * @param array<string, mixed> $o
     */
    private function expectFailure(SamlDriver $driver, array $o, string $reason, ?string $inResponseTo = null): void
    {
        $id = $this->start($driver);

        $this->expectFailureFor($driver, $inResponseTo ?? $id, $o, $reason);
    }

    /**
     * @param array<string, mixed> $o
     */
    private function expectFailureFor(SamlDriver $driver, string $inResponseTo, array $o, string $reason): void
    {
        try {
            $this->finish($driver, $inResponseTo, $o);
        } catch (AuthenticationFailed $e) {
            self::assertStringContainsString($reason, $e->getMessage());

            return;
        }

        self::fail('The driver accepted a response it must refuse (' . $reason . ').');
    }

    private function acsPost(string $samlResponse, ?string $relayState = null): ServerRequest
    {
        $body = ['SAMLResponse' => $samlResponse];

        if ($relayState !== null) {
            $body['RelayState'] = $relayState;
        }

        return (new ServerRequest('POST', FakeSamlIdp::ACS_URL))->withParsedBody($body);
    }

    private function temp(string $contents): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'sesame-saml-');
        file_put_contents($file, $contents);
        $this->tempFiles[] = $file;

        return $file;
    }
}
