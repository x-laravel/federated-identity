<?php

namespace XLaravel\FederatedIdentity\Exceptions;

class AccountExistsException extends FederatedIdentityException
{
    public static function for(string $provider): static
    {
        return static::make('account_exists', $provider);
    }
}
