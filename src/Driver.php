<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

use Psr\Http\Message\ServerRequestInterface;

/**
 * One way to verify an identity.
 *
 * A driver knows one protocol and nothing about the app: it turns a request
 * into a LoginStep, and the core does the rest. Switching driver is a config
 * change with no code change.
 */
interface Driver
{
    /**
     * The driver's name, as it appears in config.
     */
    public function name(): string;

    /**
     * Handle a login request.
     *
     * Returns a Redirect when the user must go to the identity provider, or
     * an Authenticated when the identity is already verified.
     */
    public function login(ServerRequestInterface $request): LoginStep;

    /**
     * The identity provider's logout URL, or null when the driver has none.
     *
     * @param  string  $returnTo  Where to send the user after the provider logs them out.
     */
    public function logoutUrl(string $returnTo): ?string;
}
