<?php

namespace XLaravel\FederatedIdentity\Providers\Google;

use Illuminate\Support\Str;
use XLaravel\FederatedIdentity\Verifiers\KeySet;
use XLaravel\FederatedIdentity\Verifiers\OidcTokenVerifier;

class GoogleTokenVerifier extends OidcTokenVerifier
{
    public const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public const KEYS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /**
     * @param  list<string>  $clientIds
     */
    public function __construct(KeySet $keys, array $clientIds, int $leeway = 0, string $name = 'google', string $label = 'Google')
    {
        parent::__construct($name, $label, $keys, self::ISSUERS, $clientIds, false, $leeway);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function emailVerified(array $claims): bool
    {
        if (! parent::emailVerified($claims)) {
            return false;
        }

        return Str::endsWith(Str::lower($this->stringClaim($claims, 'email') ?? ''), '@gmail.com')
            || $this->stringClaim($claims, 'hd') !== null;
    }
}
