<?php

namespace XLaravel\FederatedIdentity\Exceptions;

use Throwable;

class InvalidTokenException extends FederatedIdentityException
{
    public static function unverified(string $provider, ?Throwable $previous = null): static
    {
        return static::make('invalid_token', $provider, $previous);
    }

    public static function audience(string $provider): static
    {
        return static::make('audience', $provider);
    }

    public static function nonce(string $provider): static
    {
        return static::make('nonce', $provider);
    }
}
