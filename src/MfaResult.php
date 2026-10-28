<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

/**
 * The answer to "is this identity's MFA still good?"
 *
 * A value object: a boolean and a stable, machine-readable reason. The
 * reason is what an app or a log line can rely on; the message is the human
 * form of the same fact.
 */
final readonly class MfaResult
{
    public const SATISFIED = 'SATISFIED';

    public const NO_AUTH_TIME = 'NO_AUTH_TIME';

    public const NO_MFA_METHOD = 'NO_MFA_METHOD';

    public const TOO_OLD = 'TOO_OLD';

    public const FUTURE_AUTH_TIME = 'FUTURE_AUTH_TIME';

    public function __construct(
        public bool $satisfied,
        public string $reason,
    ) {
    }

    /**
     * The human form of the result, for a message or a log line.
     */
    public function message(): string
    {
        return match ($this->reason) {
            self::SATISFIED => 'MFA is satisfied.',
            self::NO_AUTH_TIME => 'The identity provider did not report when the user last completed MFA.',
            self::NO_MFA_METHOD => "The user's last sign-in did not use MFA.",
            self::TOO_OLD => "The user's last MFA sign-in is older than the allowed age.",
            self::FUTURE_AUTH_TIME => 'The identity provider reported a sign-in time in the future.',
            default => 'MFA could not be verified.',
        };
    }
}
