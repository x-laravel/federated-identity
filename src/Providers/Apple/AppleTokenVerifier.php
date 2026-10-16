<?php

namespace XLaravel\FederatedIdentity\Providers\Apple;

use XLaravel\FederatedIdentity\Verifiers\KeySet;
use XLaravel\FederatedIdentity\Verifiers\OidcTokenVerifier;

class AppleTokenVerifier extends OidcTokenVerifier
{
    public const ISSUER = 'https://appleid.apple.com';

    public const KEYS_URL = 'https://appleid.apple.com/auth/keys';

    /**
     * @param  list<string>  $clientIds
     */
    public function __construct(KeySet $keys, array $clientIds, int $leeway = 0, string $name = 'apple', string $label = 'Apple')
    {
        parent::__construct($name, $label, $keys, [self::ISSUER], $clientIds, true, $leeway);
    }
}
