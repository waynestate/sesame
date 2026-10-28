<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Mfa;
use Waynestate\Sesame\MfaResult;

final class MfaTest extends TestCase
{
    private const NOW = '2026-10-27T12:00:00+00:00';

    private const MAX_AGE = 28800;

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    private function identity(?string $offset, array $amr): Identity
    {
        $authTime = $offset === null ? null : (new DateTimeImmutable(self::NOW))->modify($offset);

        return new Identity('jdoe', [], 'gateway', $this->now(), $authTime, $amr);
    }

    public function testSatisfiedWhenAuthTimeIsRecentAndAmrHasMfaMethod(): void
    {
        $result = Mfa::satisfied($this->identity('-60 seconds', ['mfa']), self::MAX_AGE, $this->now());

        $this->assertSame(MfaResult::SATISFIED, $result->reason);
        $this->assertTrue($result->satisfied);
    }

    public function testNoAuthTimeWhenAuthTimeIsNull(): void
    {
        $result = Mfa::satisfied($this->identity(null, ['mfa']), self::MAX_AGE, $this->now());

        $this->assertSame(MfaResult::NO_AUTH_TIME, $result->reason);
        $this->assertFalse($result->satisfied);
    }

    public function testNoMfaMethodWhenAmrHasNoMfaMethod(): void
    {
        $result = Mfa::satisfied($this->identity('-60 seconds', ['pwd']), self::MAX_AGE, $this->now());

        $this->assertSame(MfaResult::NO_MFA_METHOD, $result->reason);
    }

    public function testNoMfaMethodWhenAmrIsEmpty(): void
    {
        $result = Mfa::satisfied($this->identity('-60 seconds', []), self::MAX_AGE, $this->now());

        $this->assertSame(MfaResult::NO_MFA_METHOD, $result->reason);
    }

    public function testTooOldWhenAuthTimeIsOlderThanMaxAge(): void
    {
        $result = Mfa::satisfied($this->identity('-28801 seconds', ['mfa']), self::MAX_AGE, $this->now());

        $this->assertSame(MfaResult::TOO_OLD, $result->reason);
    }

    public function testBoundaryExactlyMaxAgeIsSatisfied(): void
    {
        $result = Mfa::satisfied($this->identity('-28800 seconds', ['mfa']), self::MAX_AGE, $this->now());

        $this->assertSame(MfaResult::SATISFIED, $result->reason);
    }

    public function testFutureAuthTimeWhenAuthTimeIsBeyondTheSkew(): void
    {
        $result = Mfa::satisfied($this->identity('+61 seconds', ['mfa']), self::MAX_AGE, $this->now());

        $this->assertSame(MfaResult::FUTURE_AUTH_TIME, $result->reason);
    }

    public function testFutureAuthTimeWithinTheSkewIsSatisfied(): void
    {
        $result = Mfa::satisfied($this->identity('+60 seconds', ['mfa']), self::MAX_AGE, $this->now());

        $this->assertSame(MfaResult::SATISFIED, $result->reason);
    }

    public function testCasIdentityIsAlwaysDenied(): void
    {
        $identity = new Identity('jdoe', [], 'cas', $this->now());

        $result = Mfa::satisfied($identity, self::MAX_AGE, $this->now());

        $this->assertSame(MfaResult::NO_AUTH_TIME, $result->reason);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function mfaMethods(): array
    {
        return [
            'mfa' => ['mfa'],
            'otp' => ['otp'],
            'hwk' => ['hwk'],
            'swk' => ['swk'],
            'fido' => ['fido'],
            'sms' => ['sms'],
            'pop' => ['pop'],
        ];
    }

    #[DataProvider('mfaMethods')]
    public function testEachMfaMethodCounts(string $method): void
    {
        $result = Mfa::satisfied($this->identity('-60 seconds', [$method]), self::MAX_AGE, $this->now());

        $this->assertSame(MfaResult::SATISFIED, $result->reason);
    }

    public function testAMfaMethodAmongOtherValuesCounts(): void
    {
        $result = Mfa::satisfied($this->identity('-60 seconds', ['pwd', 'x509', 'otp']), self::MAX_AGE, $this->now());

        $this->assertSame(MfaResult::SATISFIED, $result->reason);
    }

    public function testNonMfaAmrValuesAloneDoNotCount(): void
    {
        $result = Mfa::satisfied($this->identity('-60 seconds', ['pwd', 'x509']), self::MAX_AGE, $this->now());

        $this->assertSame(MfaResult::NO_MFA_METHOD, $result->reason);
    }

    public function testTheMessageMatchesTheReason(): void
    {
        $result = Mfa::satisfied($this->identity(null, []), self::MAX_AGE, $this->now());

        $this->assertSame('The identity provider did not report when the user last completed MFA.', $result->message());
    }
}
