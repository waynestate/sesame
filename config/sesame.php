<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | The protocol SESAME uses to verify identities: "cas" for the
    | university's CAS server, or "fake" for local development.
    |
    */

    'driver' => env('SESAME_DRIVER', 'cas'),

    /*
    |--------------------------------------------------------------------------
    | CAS
    |--------------------------------------------------------------------------
    |
    | The CAS server's base URL (no trailing slash) and the URL the server
    | sends the user back to after verifying them. When service_url is not
    | set, the adapter uses the app's /login route.
    |
    */

    'cas' => [
        'base_url' => env('SESAME_CAS_BASE_URL'),
        'service_url' => env('SESAME_CAS_SERVICE_URL'),
        'protocol' => env('SESAME_CAS_PROTOCOL', '3.0'),
        'renew' => env('SESAME_CAS_RENEW', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fake driver
    |--------------------------------------------------------------------------
    |
    | The identity the fake driver returns, for local development.
    |
    */

    'fake' => [
        'username' => env('SESAME_FAKE_USERNAME'),
        'attributes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session timeouts
    |--------------------------------------------------------------------------
    |
    | How long a signed-in session may live, in minutes. The idle timeout is
    | the quiet time after which the session is dropped; the absolute
    | lifetime is the longest a session may live, no matter how active it
    | is.
    |
    */

    'session' => [
        'idle_minutes' => env('SESAME_SESSION_IDLE_MINUTES', 120),
        'absolute_minutes' => env('SESAME_SESSION_ABSOLUTE_MINUTES', 720),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | When true, the adapter registers GET /login (sesame.login) and
    | POST /logout (sesame.logout). Set to false to keep the routes in the
    | app's own route file; the auth.sesame middleware still points at the
    | sesame.login route name.
    |
    */

    'routes' => env('SESAME_ROUTES', true),

    /*
    |--------------------------------------------------------------------------
    | User resolver
    |--------------------------------------------------------------------------
    |
    | The class that turns a verified identity into the app's user. It must
    | implement Waynestate\Sesame\UserResolver.
    |
    */

    'user_resolver' => env('SESAME_USER_RESOLVER'),

];
