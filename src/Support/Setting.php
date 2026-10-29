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
 */
final class Setting
{
    /**
     * The value of the named setting, or null when neither the environment
     * nor waynestate/playbook provides one.
     */
    public static function get(string $key): ?string
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

        if (class_exists(\Waynestate\Playbook\Playbook::class)) {
            return \Waynestate\Playbook\Playbook::get($key);
        }

        return null;
    }
}
