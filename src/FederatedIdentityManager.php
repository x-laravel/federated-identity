<?php

namespace XLaravel\FederatedIdentity;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use XLaravel\FederatedIdentity\Contracts\TokenVerifier;
use XLaravel\FederatedIdentity\Providers\Apple\AppleClientSecret;
use XLaravel\FederatedIdentity\Providers\Apple\AppleTokenRevoker;
use XLaravel\FederatedIdentity\Providers\Apple\AppleTokenVerifier;
use XLaravel\FederatedIdentity\Providers\Google\GoogleTokenVerifier;
use XLaravel\FederatedIdentity\Support\FederatedIdentityConfig;
use XLaravel\FederatedIdentity\Verifiers\KeySet;
use XLaravel\FederatedIdentity\Verifiers\OidcTokenVerifier;

class FederatedIdentityManager extends Manager
{
    public function verifier(string $name): TokenVerifier
    {
        $verifier = $this->driver($name);

        if (! $verifier instanceof TokenVerifier) {
            throw new InvalidArgumentException("Provider [{$name}] must resolve to an instance of " . TokenVerifier::class . '.');
        }

        return $verifier;
    }

    public function has(string $name): bool
    {
        return isset($this->customCreators[$name]) || in_array($name, FederatedIdentityConfig::providerNames(), true);
    }

    /**
     * @return list<string>
     */
    public function providers(): array
    {
        return array_values(array_unique([...FederatedIdentityConfig::providerNames(), ...array_keys($this->customCreators)]));
    }

    public function label(string $name): string
    {
        if (! $this->has($name)) {
            return Str::headline($name);
        }

        try {
            return $this->verifier($name)->label();
        } catch (InvalidArgumentException) {
            return Str::headline($name);
        }
    }

    public function revoker(string $name): AppleTokenRevoker
    {
        return new AppleTokenRevoker(
            http: $this->container->make(Http::class),
            secret: $this->appleClientSecret($name),
            timeout: FederatedIdentityConfig::integer('http.timeout', 10),
        );
    }

    public function appleClientSecret(string $name): AppleClientSecret
    {
        $config = FederatedIdentityConfig::provider($name);

        if (! in_array($name, FederatedIdentityConfig::providerNames(), true) || (FederatedIdentityConfig::text($config['driver'] ?? null) ?: $name) !== 'apple') {
            throw new InvalidArgumentException("Provider [{$name}] is not an Apple provider; only Apple tokens can be revoked.");
        }

        return new AppleClientSecret(
            teamId: FederatedIdentityConfig::text($config['team_id'] ?? null),
            keyId: FederatedIdentityConfig::text($config['key_id'] ?? null),
            privateKey: $this->privateKey(FederatedIdentityConfig::text($config['private_key'] ?? null)),
            clientIds: FederatedIdentityConfig::list($config['client_ids'] ?? null),
            name: $name,
        );
    }

    public function keySet(string $name, string $url): KeySet
    {
        $store = FederatedIdentityConfig::string('cache.store');
        $prefix = FederatedIdentityConfig::string('cache.prefix') ?: 'federated-identity';

        return new KeySet(
            http: $this->container->make(Http::class),
            cache: $this->container->make(CacheFactory::class)->store($store !== '' ? $store : null),
            url: $url,
            cacheKey: "{$prefix}:jwks:{$name}",
            timeout: FederatedIdentityConfig::integer('http.timeout', 10),
            ttl: FederatedIdentityConfig::integer('cache.ttl', 86400),
            refreshAfter: FederatedIdentityConfig::integer('cache.refresh_after', 60),
        );
    }

    public function getDefaultDriver(): string
    {
        throw new InvalidArgumentException('Name the provider to verify a token for.');
    }

    /**
     * @param  string  $driver
     */
    protected function createDriver($driver): mixed
    {
        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($driver);
        }

        $config = FederatedIdentityConfig::provider($driver);

        if ($config === [] && ! in_array($driver, FederatedIdentityConfig::providerNames(), true)) {
            throw new InvalidArgumentException("Provider [{$driver}] is not configured.");
        }

        return match (FederatedIdentityConfig::text($config['driver'] ?? null) ?: $driver) {
            'google' => $this->createGoogle($driver, $config),
            'apple' => $this->createApple($driver, $config),
            'oidc' => $this->createOidc($driver, $config),
            default => throw new InvalidArgumentException("Provider [{$driver}] has no driver. Set its \"driver\" to \"oidc\" or register it with FederatedIdentity::extend()."),
        };
    }

    protected function privateKey(string $privateKey): string
    {
        if ($privateKey === '' || Str::startsWith($privateKey, ['-----BEGIN', '/'])) {
            return $privateKey;
        }

        return $this->container instanceof Application ? $this->container->basePath($privateKey) : $privateKey;
    }

    /**
     * @param  string  $driver
     */
    protected function callCustomCreator($driver): mixed
    {
        return $this->customCreators[$driver]($this->container, FederatedIdentityConfig::provider($driver), $driver);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createGoogle(string $name, array $config): GoogleTokenVerifier
    {
        return new GoogleTokenVerifier(
            keys: $this->keySet($name, GoogleTokenVerifier::KEYS_URL),
            clientIds: FederatedIdentityConfig::list($config['client_ids'] ?? null),
            leeway: FederatedIdentityConfig::integer('leeway', 60),
            name: $name,
            label: FederatedIdentityConfig::text($config['label'] ?? null) ?: 'Google',
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createApple(string $name, array $config): AppleTokenVerifier
    {
        return new AppleTokenVerifier(
            keys: $this->keySet($name, AppleTokenVerifier::KEYS_URL),
            clientIds: FederatedIdentityConfig::list($config['client_ids'] ?? null),
            leeway: FederatedIdentityConfig::integer('leeway', 60),
            name: $name,
            label: FederatedIdentityConfig::text($config['label'] ?? null) ?: 'Apple',
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createOidc(string $name, array $config): OidcTokenVerifier
    {
        $issuers = FederatedIdentityConfig::list($config['issuer'] ?? null);
        $jwksUri = FederatedIdentityConfig::text($config['jwks_uri'] ?? null);

        if ($issuers === [] || $jwksUri === '') {
            throw new InvalidArgumentException("Provider [{$name}] needs an \"issuer\" and a \"jwks_uri\".");
        }

        foreach ([...$issuers, $jwksUri] as $url) {
            if (! Str::startsWith($url, 'https://')) {
                throw new InvalidArgumentException("Provider [{$name}] must use https for its \"issuer\" and \"jwks_uri\".");
            }
        }

        return new OidcTokenVerifier(
            name: $name,
            label: FederatedIdentityConfig::text($config['label'] ?? null) ?: Str::headline($name),
            keys: $this->keySet($name, $jwksUri),
            issuers: $issuers,
            clientIds: FederatedIdentityConfig::list($config['client_ids'] ?? null),
            requiresNonce: filter_var($config['nonce'] ?? false, FILTER_VALIDATE_BOOL),
            leeway: FederatedIdentityConfig::integer('leeway', 60),
        );
    }
}
