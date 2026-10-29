<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\ReturnTo;

/**
 * The return-to rule: same scheme, host and effective port as the service
 * URL, https (or http on localhost), and no credentials in the URL.
 */
final class ReturnToTest extends TestCase
{
    private const SERVICE = 'https://app.example.test/login/callback';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function kept(): iterable
    {
        yield 'same origin' => ['https://app.example.test/reports', self::SERVICE];
        yield 'explicit default port' => ['https://app.example.test:443/reports', self::SERVICE];
        yield 'default port on the service' => ['https://app.example.test/x', 'https://app.example.test:443/'];
        yield 'host case differs' => ['https://APP.Example.TEST/x', self::SERVICE];
        yield 'same non-default port' => ['https://app.example.test:8443/x', 'https://app.example.test:8443/login'];
        yield 'http on localhost, same port' => ['http://localhost:8000/x', 'http://localhost:8000/login/callback'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'other host' => ['https://evil.example.test/', self::SERVICE];
        yield 'other port' => ['https://app.example.test:8443/', self::SERVICE];
        yield 'port missing where the service has one' => ['https://app.example.test/', 'https://app.example.test:8443/login'];
        yield 'http downgrade' => ['http://app.example.test/', self::SERVICE];
        yield 'http to port 443' => ['http://app.example.test:443/', self::SERVICE];
        yield 'userinfo' => ['https://user@app.example.test/', self::SERVICE];
        yield 'user and password' => ['https://user:pw@app.example.test/', self::SERVICE];
        yield 'empty userinfo trick' => ['https://evil.example.test@app.example.test/', self::SERVICE];
        yield 'relative' => ['/reports', self::SERVICE];
        yield 'protocol relative' => ['//evil.example.test/', self::SERVICE];
        yield 'http on a non-local host' => ['http://app.example.test/', 'http://app.example.test/login'];
        yield 'https to an http localhost service' => ['https://localhost:8000/x', 'http://localhost:8000/login'];
        yield 'localhost other port' => ['http://localhost:9000/x', 'http://localhost:8000/login'];
        yield 'javascript' => ['javascript:alert(1)', self::SERVICE];
    }

    #[DataProvider('kept')]
    public function testASameOriginReturnToIsKept(string $returnTo, string $service): void
    {
        self::assertSame($returnTo, ReturnTo::safe($returnTo, $service));
    }

    #[DataProvider('refused')]
    public function testAnythingElseFallsBackToTheServiceUrl(string $returnTo, string $service): void
    {
        self::assertSame($service, ReturnTo::safe($returnTo, $service));
    }

    public function testWithoutAServiceUrlTheReturnToIsUsedAsIs(): void
    {
        self::assertSame('https://anywhere.example.test/', ReturnTo::safe('https://anywhere.example.test/', null));
    }
}
