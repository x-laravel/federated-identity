<?php

namespace XLaravel\FederatedIdentity\Providers\Apple;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

class AppleTokenRevoker
{
    public const TOKEN_URL = 'https://appleid.apple.com/auth/token';

    public const REVOKE_URL = 'https://appleid.apple.com/auth/revoke';

    public function __construct(
        protected Http $http,
        protected AppleClientSecret $secret,
        protected int $timeout = 10,
    ) {
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function revoke(string $clientId, string $authorizationCode, ?string $redirectUri = null): void
    {
        $this->revokeToken($clientId, $this->refreshToken($clientId, $authorizationCode, $redirectUri));
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function refreshToken(string $clientId, string $authorizationCode, ?string $redirectUri = null): string
    {
        $fields = [
            'client_id' => $clientId,
            'client_secret' => $this->secret->for($clientId),
            'code' => $authorizationCode,
            'grant_type' => 'authorization_code',
        ];

        if ($redirectUri !== null) {
            $fields['redirect_uri'] = $redirectUri;
        }

        $refreshToken = $this->http->asForm()->timeout($this->timeout)->post(self::TOKEN_URL, $fields)->throw()->json('refresh_token');

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new UnexpectedValueException('Apple returned no refresh token for the authorization code.');
        }

        return $refreshToken;
    }

    /**
     * @param  'refresh_token'|'access_token'  $type
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function revokeToken(string $clientId, string $token, string $type = 'refresh_token'): void
    {
        $this->http->asForm()->timeout($this->timeout)->post(self::REVOKE_URL, [
            'client_id' => $clientId,
            'client_secret' => $this->secret->for($clientId),
            'token' => $token,
            'token_type_hint' => $type,
        ])->throw();
    }
}
