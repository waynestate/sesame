<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Oidc;

use League\OAuth2\Client\Provider\GenericProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waynestate\Sesame\Exception\AuthenticationFailed;

/**
 * league/oauth2-client's generic provider, minus one legacy parameter, and
 * with redirects never followed.
 *
 * The library builds the authorization URL, generates the PKCE verifier and
 * its S256 challenge, and runs the token request with the client's
 * credentials. Its default authorization query also carries
 * "approval_prompt=auto", a pre-standard Google parameter that OpenID
 * Connect does not define; SESAME drops it so the authorization request is
 * exactly what the driver asked for.
 *
 * The token request carries the code, the PKCE verifier and (for
 * client_secret_post) the client secret. A redirect from the token
 * endpoint would make Guzzle resend that body to wherever the Location
 * header points, so every token request runs with redirects off, whatever
 * the app's Guzzle client is configured to do, and a 3xx answer is an
 * error.
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

    /**
     * Send the token request with redirects off; a 3xx answer fails.
     */
    public function getResponse(RequestInterface $request): ResponseInterface
    {
        $response = $this->getHttpClient()->send($request, ['allow_redirects' => false, 'http_errors' => false]);
        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400) {
            throw new AuthenticationFailed(sprintf('The token endpoint answered %d, a redirect, which is never followed (oidc.token_redirect).', $status));
        }

        return $response;
    }
}
