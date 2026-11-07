# SAML test fixtures: TEST ONLY, NEVER TRUST

These key pairs exist so the saml driver's tests can sign and verify real SAML messages without a network or a real
IdP. They are published in this repository, so anyone can sign with them. Never load them into an app, never put their
certificates in an IdP's or SP's metadata, and never trust a signature they made.

| File | What it is |
|---|---|
| `idp-test-only.key`, `idp-test-only.crt` | The fixture IdP's signing key and certificate (`tests/Support/FakeSamlIdp.php` signs responses with them) |
| `sp-test-only.key`, `sp-test-only.crt` | The fixture SP's key pair, for the signed AuthnRequest and SP metadata tests |

Both are self-signed RSA 2048 with SHA-256, with subjects ending `-TEST-ONLY.example.test` and the organization
"SESAME test fixture, never trust". The attacker key pair some tests need is generated fresh in each run and never
written to disk.

To make a new pair (if one is ever needed), from this folder:

```sh
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 36500 \
  -subj "/CN=sesame-idp-TEST-ONLY.example.test/O=SESAME test fixture, never trust" \
  -keyout idp-test-only.key -out idp-test-only.crt
```
