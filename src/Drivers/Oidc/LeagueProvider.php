<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Oidc;

use League\OAuth2\Client\Provider\GenericProvider;

/**
 * league/oauth2-client's generic provider, minus one legacy parameter.
 *
 * The library builds the authorization URL, generates the PKCE verifier and
 * its S256 challenge, and runs the token request with the client's
 * credentials. Its default authorization query also carries
 * "approval_prompt=auto", a pre-standard Google parameter that OpenID
 * Connect does not define; SESAME drops it so the authorization request is
 * exactly what the driver asked for.
 *
 * Only the oidc driver builds this class, and only after it has checked
 * that league/oauth2-client is installed.
 *
 * @internal
 */
final class LeagueProvider extends GenericProvider
{
    /**
     * @param array<mixed> $options
     * @return array<string, mixed>
     */
    protected function getAuthorizationParameters(array $options): array
    {
        $params = parent::getAuthorizationParameters($options);
        unset($params['approval_prompt']);

        return $params;
    }
}
