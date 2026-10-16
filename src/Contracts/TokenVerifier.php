<?php

namespace XLaravel\FederatedIdentity\Contracts;

use XLaravel\FederatedIdentity\Exceptions\InvalidTokenException;
use XLaravel\FederatedIdentity\Exceptions\ProviderUnavailableException;
use XLaravel\FederatedIdentity\ProviderUser;

interface TokenVerifier
{
    public function name(): string;

    public function label(): string;

    public function requiresNonce(): bool;

    /**
     * @throws InvalidTokenException
     * @throws ProviderUnavailableException
     */
    public function verify(string $idToken, ?string $nonce = null): ProviderUser;
}
