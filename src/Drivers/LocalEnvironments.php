<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

/**
 * The environments SESAME treats as a developer's own machine or a test run.
 *
 * One allowlist, shared by the fake driver (which only boots here) and the
 * CAS driver (which accepts a plain http localhost address only here). It is
 * an allowlist on purpose: production, staging, or anything nobody wrote down
 * is not local, so a typo in the environment fails closed. "Prod-ish" is not
 * an environment, it is a mood.
 */
final class LocalEnvironments
{
    /**
     * The environment names that count as local, compared lowercased and
     * trimmed.
     *
     * @var list<string>
     */
    public const ALLOWED = ['local', 'development', 'dev', 'testing', 'test'];

    public static function isLocal(string $environment): bool
    {
        return in_array(strtolower(trim($environment)), self::ALLOWED, true);
    }
}
