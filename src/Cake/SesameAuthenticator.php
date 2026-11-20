<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Cake;

use Authentication\Authenticator\AbstractAuthenticator;
use Authentication\Authenticator\PersistenceInterface;
use Authentication\Authenticator\Result;
use Authentication\Authenticator\ResultInterface;
use Authentication\UrlChecker\UrlCheckerTrait;
use ArrayAccess;
use Cake\Http\Exception\RedirectException;
use Closure;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Waynestate\Sesame\Sesame;

/**
 * An authenticator for cakephp/authentication 3.x that signs people in through SESAME.
 *
 * It asks the app's own Sesame whether someone is signed in: if the session holds
 * a user, the request is authenticated with that user; if not, and the request is
 * not already on the login route, it sends the browser to the login route with a
 * `redirect` parameter pointing at the page the visitor asked for. The login route
 * calls `Sesame::login()`, which checks the ticket and stores the user.
 *
 * The `sesame` config is a closure that builds the app's Sesame from the request,
 * because the authenticator is built once while the app's own wiring happens per
 * request. `clearIdentity()` ends the local sign-in through `Sesame::signOutLocal()`,
 * so the framework's `logout()` clears what SESAME stored.
 *
 * The identity the framework receives is the stored user itself when it is an
 * array (or `ArrayAccess`), and `['user' => <user>]` otherwise: the framework's
 * Result only carries array identities, while the core stores whatever the app's
 * resolver returned.
 */
class SesameAuthenticator extends AbstractAuthenticator implements PersistenceInterface
{
    use UrlCheckerTrait;

    /**
     * Default config for this object.
     *
     * - `sesame` A closure taking the request and returning the app's Sesame. Required.
     * - `loginUrl` The path of the route that starts the sign-in, or null to never redirect.
     * - `urlChecker` Options for the URL checker that decides which requests are on the login route.
     *
     * @var array<string, mixed>
     * @phpstan-ignore property.phpDocType (the parent documents this property as plain `array`)
     */
    protected array $_defaultConfig = [
        'sesame' => null,
        'loginUrl' => null,
        'urlChecker' => [],
    ];

    public function authenticate(ServerRequestInterface $request): ResultInterface
    {
        $user = $this->sesame($request)->user();
        if ($user !== null) {
            $data = is_array($user) || $user instanceof ArrayAccess
                ? $user
                : ['user' => $user];

            return new Result($data, Result::SUCCESS);
        }

        $loginUrl = $this->getConfig('loginUrl');
        if (is_string($loginUrl) && $loginUrl !== '' && ! $this->_checkUrl($request)) {
            throw new RedirectException(
                $loginUrl . '?' . http_build_query(['redirect' => $request->getUri()->getPath()]),
            );
        }

        return new Result(null, Result::FAILURE_CREDENTIALS_MISSING);
    }

    /**
     * SESAME stores the identity itself when the login route runs, so there is
     * nothing to persist here; the service only keeps the identity attribute.
     */
    /**
     * @phpstan-ignore missingType.generics (the interface leaves $identity untyped),missingType.iterableValue (SESAME stores whatever the app's resolver returned)
     */
    public function persistIdentity(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ArrayAccess|array $identity,
    ): array {
        return [
            'request' => $request,
            'response' => $response,
        ];
    }

    /**
     * Ends the local sign-in, the way an app action calling `Sesame::signOutLocal()` would.
     */
    public function clearIdentity(ServerRequestInterface $request, ResponseInterface $response): array
    {
        $this->sesame($request)->signOutLocal();

        return [
            'request' => $request,
            'response' => $response,
        ];
    }

    /**
     * Builds the app's Sesame through the `sesame` config closure.
     */
    private function sesame(ServerRequestInterface $request): Sesame
    {
        $factory = $this->getConfig('sesame');
        if (! $factory instanceof Closure) {
            throw new InvalidArgumentException(
                'The sesame authenticator needs its `sesame` config: a closure that builds the app\'s Sesame from the request.',
            );
        }

        $sesame = $factory($request);
        if (! $sesame instanceof Sesame) {
            throw new InvalidArgumentException(
                'The sesame authenticator\'s `sesame` closure must return a Sesame.',
            );
        }

        return $sesame;
    }
}
