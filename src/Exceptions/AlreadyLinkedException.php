<?php

namespace XLaravel\FederatedIdentity\Exceptions;

class AlreadyLinkedException extends FederatedIdentityException
{
    public static function for(string $provider): static
    {
        return static::make('already_linked', $provider);
    }
}
