<?php

declare(strict_types=1);

// The app's SESAME config: the file that replaces the old Wayne Auth class.
//
// A real app requires its own vendor/autoload.php, sets cas.base_url to
// https://academica.aws.wayne.edu/cas (or passes
// Setting::get('SESAME_CAS_BASE_URL'): boot() reads only this array), and
// passes no $http (Guzzle is found).
// This fixture points at a CAS server that does not exist and answers the
// ticket validation with SESAME's own CAS test fixtures, so the sign-in runs
// end to end without a network.

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Waynestate\Sesame\Tests\Support\FakeHttpClient;
use Waynestate\Sesame\WayneAuth\WayneAuth;

require_once __DIR__ . '/../../../../vendor/autoload.php';

$casAnswer = ($_GET['ticket'] ?? null) === 'ST-1-fixture' ? 'success.xml' : 'failure-invalid-ticket.xml';

WayneAuth::boot(
    [
        'driver' => 'cas',
        'environment' => 'production',
        'cas' => [
            'base_url' => 'https://cas.test/cas',
            'service_url' => 'https://app.test/',
        ],
    ],
    new FakeHttpClient([
        (new Response())->withBody(Stream::create((string) file_get_contents(__DIR__ . '/../../cas/' . $casAnswer))),
    ]),
);
