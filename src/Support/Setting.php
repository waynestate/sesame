<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Support;

/**
 * The one lookup for the library's shared settings.
 *
 * The real environment wins (getenv, then $_ENV, then $_SERVER). When it has
 * nothing, the private waynestate/playbook package supplies Wayne State's
 * shared values (the CAS server, the Entra tenant, the gateway) when it is
 * installed. An empty value counts as missing, the same way Playbook::get()
 * treats one, so a blank variable never shadows the playbook.
 *
 * Secrets are environment-only: a key in ENVIRONMENT_ONLY never falls back
 * to the playbook, so a shared package can never supply an app's secret.
 */
final class Setting
{
    /**
     * The settings that are read from the environment and nowhere else.
     *
     * @var list<string>
     */
    public const ENVIRONMENT_ONLY = ['SESAME_OIDC_CLIENT_SECRET'];

    /**
     * The value of the named setting, or null when neither the environment
     * nor waynestate/playbook provides one.
     */
    public static function get(string $key): ?string
    {
        $value = self::env($key);

        if ($value !== null) {
            return $value;
        }

        if (in_array($key, self::ENVIRONMENT_ONLY, true)) {
            return null;
        }

        if (class_exists(\Waynestate\Playbook\Playbook::class)) {
            return \Waynestate\Playbook\Playbook::get($key);
        }

        return null;
    }

    /**
     * The value of the named setting from the real environment only
     * (getenv, then $_ENV, then $_SERVER), or null when it is unset or
     * empty. The playbook is never consulted.
     */
    public static function env(string $key): ?string
    {
        $value = getenv($key);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = $_ENV[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = $_SERVER[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
