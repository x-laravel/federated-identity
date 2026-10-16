<?php

namespace XLaravel\FederatedIdentity\Exceptions;

class EmailMissingException extends FederatedIdentityException
{
    public static function for(string $provider): static
    {
        return static::make('email_missing', $provider);
    }
}
