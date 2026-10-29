<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Oidc;

use InvalidArgumentException;
use League\OAuth2\Client\OptionProvider\PostAuthOptionProvider;

/**
 * client_secret_basic, as RFC 6749 section 2.3.1 spells it.
 *
 * The client id and secret are form-encoded before they are joined and
 * base64-encoded, so a secret with characters such as "~", "+" or ":" is
 * sent the way the authorization server decodes it. league/oauth2-client's
 * own basic provider skips that encoding step.
 *
 * @internal
 */
final class BasicAuthOptionProvider extends PostAuthOptionProvider
{
    /**
     * @param string $method
     * @param array<mixed> $params
     * @return array<string, mixed>
     */
    public function getAccessTokenOptions($method, array $params): array
    {
        $id = $params['client_id'] ?? null;
        $secret = $params['client_secret'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            // The values are never part of the message.
            throw new InvalidArgumentException('client_secret_basic needs a client id and a client secret.');
        }

        $credentials = base64_encode(urlencode($id) . ':' . urlencode($secret));
        unset($params['client_id'], $params['client_secret']);

        /** @var array<string, mixed> $options */
        $options = parent::getAccessTokenOptions($method, $params);
        $options['headers']['Authorization'] = 'Basic ' . $credentials;

        return $options;
    }
}
