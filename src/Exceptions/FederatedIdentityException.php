<?php

namespace XLaravel\FederatedIdentity\Exceptions;

use Illuminate\Container\Container;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use XLaravel\FederatedIdentity\FederatedIdentityManager;

abstract class FederatedIdentityException extends RuntimeException
{
    final public function __construct(string $message, public readonly string $provider, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    protected static function make(string $key, string $provider, ?Throwable $previous = null): static
    {
        $message = trans("federated-identity::messages.{$key}", ['provider' => static::label($provider)]);

        return new static(is_string($message) ? $message : $key, $provider, $previous);
    }

    protected static function label(string $provider): string
    {
        $container = Container::getInstance();

        return $container->bound(FederatedIdentityManager::class)
            ? $container->make(FederatedIdentityManager::class)->label($provider)
            : Str::headline($provider);
    }
}
