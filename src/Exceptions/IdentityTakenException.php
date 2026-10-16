<?php

namespace XLaravel\FederatedIdentity\Exceptions;

class IdentityTakenException extends FederatedIdentityException
{
    public static function for(string $provider): static
    {
        return static::make('identity_taken', $provider);
    }
}
