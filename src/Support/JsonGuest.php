<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The answer for a script's request (XHR or fetch) that finds no one signed
 * in: a 401 with `{"signedIn":false}`, never a redirect.
 *
 * A redirect to the identity provider is useless to a script: the browser
 * follows it to another origin, the answer is an HTML sign-in page the
 * script cannot read, and the user sees a save that silently failed. The
 * Laravel middleware has always answered such requests with a 401; the
 * raw-PHP sesame_login() and the Wayne Auth bridge answer them the same way,
 * with the body sesame_status() sends for a signed-out session, so one
 * client check (`status === 401`) covers every path. The page itself still
 * sends people to the sign-in on their next navigation.
 *
 * @internal Used by sesame_login() and the Wayne Auth bridge.
 */
final class JsonGuest
{
    /** The body of the answer, the signed-out shape of sesame_status(). */
    public const BODY = '{"signedIn":false}';

    /**
     * Whether the request comes from a script that wants JSON back.
     *
     * The rule is Laravel's expectsJson(), so the raw-PHP path and the
     * Laravel path agree: the first type in Accept is JSON (`.../json` or
     * `...+json`), or the request carries `X-Requested-With: XMLHttpRequest`
     * (what jQuery and most XHR helpers send), is not a pjax request, and
     * accepts any type. A plain browser navigation is neither.
     */
    public static function expected(ServerRequestInterface $request): bool
    {
        $accept = strtolower(trim(explode(',', $request->getHeaderLine('Accept'))[0]));
        $first = trim(explode(';', $accept)[0]);

        if (str_contains($first, '/json') || str_contains($first, '+json')) {
            return true;
        }

        $ajax = strcasecmp($request->getHeaderLine('X-Requested-With'), 'XMLHttpRequest') === 0;

        return $ajax
            && ! $request->hasHeader('X-PJAX')
            && ($first === '' || $first === '*/*' || $first === '*');
    }

    /**
     * Send the 401 and stop the script, so no protected code runs.
     *
     * The headers are the ones sesame_status() sends: no-store, so no cache
     * keeps who is (not) signed in, JSON in UTF-8, and nosniff.
     */
    public static function send(): never
    {
        header('Cache-Control: no-store', true);
        header('Content-Type: application/json; charset=utf-8', true);
        header('X-Content-Type-Options: nosniff', true);
        http_response_code(401);
        echo self::BODY;

        exit;
    }
}
