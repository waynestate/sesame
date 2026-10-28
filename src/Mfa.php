<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

use DateTimeImmutable;

/**
 * The one rule that decides whether an identity's MFA is still good.
 *
 * "Open sesame" is not enough for a door that needs a second key. An
 * identity satisfies MFA only when the identity provider reported a sign-in
 * time (auth_time), that time is recent enough, and the sign-in used an MFA
 * method (amr). Anything the provider did not report is treated as missing,
 * so the check fails closed: a driver that carries no MFA facts is always
 * denied. The cas driver carries none by default, so it is denied until
 * cas.assume_mfa is on.
 */
final class Mfa
{
    /**
     * The amr values that count as MFA.
     *
     * @var list<string>
     */
    public const MFA_METHODS = ['mfa', 'otp', 'hwk', 'swk', 'fido', 'sms', 'pop'];

    /**
     * How far into the future a reported sign-in time may sit before it is
     * treated as a clock problem, in seconds.
     */
    public const FUTURE_SKEW_SECONDS = 60;

    /**
     * Decide whether the identity's MFA is still good.
     *
     * The checks run in a fixed order, so the reason is stable:
     *   1. no auth_time             -> NO_AUTH_TIME
     *   2. auth_time in the future  -> FUTURE_AUTH_TIME
     *   3. auth_time too old        -> TOO_OLD
     *   4. no MFA method in amr     -> NO_MFA_METHOD
     *   5. otherwise                -> SATISFIED
     *
     * A sign-in exactly $maxAgeSeconds old is still satisfied.
     */
    public static function satisfied(Identity $identity, int $maxAgeSeconds, DateTimeImmutable $now): MfaResult
    {
        $authTime = $identity->authTime;

        if ($authTime === null) {
            return new MfaResult(false, MfaResult::NO_AUTH_TIME);
        }

        $nowTs = $now->getTimestamp();
        $authTs = $authTime->getTimestamp();

        if ($authTs > $nowTs + self::FUTURE_SKEW_SECONDS) {
            return new MfaResult(false, MfaResult::FUTURE_AUTH_TIME);
        }

        if ($nowTs - $authTs > $maxAgeSeconds) {
            return new MfaResult(false, MfaResult::TOO_OLD);
        }

        if (! self::hasMfaMethod($identity->amr)) {
            return new MfaResult(false, MfaResult::NO_MFA_METHOD);
        }

        return new MfaResult(true, MfaResult::SATISFIED);
    }

    /**
     * Whether the amr list carries any method that counts as MFA.
     *
     * @param  list<string>  $amr
     */
    private static function hasMfaMethod(array $amr): bool
    {
        foreach ($amr as $method) {
            if (in_array($method, self::MFA_METHODS, true)) {
                return true;
            }
        }

        return false;
    }
}
