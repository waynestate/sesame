<?php

declare(strict_types=1);

namespace Waynestate\Sesame\WayneCas;

use Illuminate\Support\Facades\Log;

/**
 * Log the wayne-cas deprecation once per process.
 *
 * The shim is a bridge for apps moving off wayne-cas, and it says so once
 * instead of on every request.
 */
final class Deprecation
{
    public const MESSAGE = "wayne-cas compatibility is deprecated; move to SESAME's own config and middleware.";

    private static bool $logged = false;

    private static bool $twoFaLogged = false;

    public static function logOnce(): void
    {
        if (self::$logged) {
            return;
        }

        self::$logged = true;

        Log::warning(self::MESSAGE);

        if ((bool) config('wayne-cas.use2FA')) {
            self::logTwoFaOnce();
        }
    }

    /**
     * wayne-cas use2FA runs a Duo challenge the shim does not carry, so it
     * says so once when the app still has it switched on.
     */
    public static function logTwoFaOnce(): void
    {
        if (self::$twoFaLogged) {
            return;
        }

        self::$twoFaLogged = true;

        Log::warning('wayne-cas use2FA is not supported by the SESAME shim; handle Duo 2FA in the app.');
    }

    /**
     * Reset the once-per-process flags, for tests.
     */
    public static function reset(): void
    {
        self::$logged = false;
        self::$twoFaLogged = false;
    }
}
