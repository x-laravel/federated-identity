<?php

namespace XLaravel\FederatedIdentity\Exceptions;

use Throwable;

class ProviderUnavailableException extends FederatedIdentityException
{
    public static function for(string $provider, ?Throwable $previous = null): static
    {
        return static::make('provider_unavailable', $provider, $previous);
    }
}
