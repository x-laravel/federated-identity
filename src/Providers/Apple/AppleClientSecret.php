<?php

namespace XLaravel\FederatedIdentity\Providers\Apple;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AppleClientSecret
{
    /**
     * @param  list<string>  $clientIds
     */
    public function __construct(
        protected string $teamId,
        protected string $keyId,
        protected string $privateKey,
        protected array $clientIds,
        protected int $ttl = 300,
        protected string $name = 'apple',
    ) {
    }

    public function for(string $clientId): string
    {
        if (! in_array($clientId, $this->clientIds, true)) {
            throw new InvalidArgumentException("[{$clientId}] is not one of the configured Apple client IDs.");
        }

        if ($this->teamId === '' || $this->keyId === '') {
            throw new InvalidArgumentException("Set federated-identity.providers.{$this->name}.team_id and federated-identity.providers.{$this->name}.key_id to sign Apple client secrets.");
        }

        $now = now()->getTimestamp();

        return JWT::encode([
            'iss' => $this->teamId,
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'aud' => AppleTokenVerifier::ISSUER,
            'sub' => $clientId,
        ], $this->key(), 'ES256', $this->keyId);
    }

    protected function key(): string
    {
        if (Str::startsWith($this->privateKey, '-----BEGIN')) {
            return $this->privateKey;
        }

        if ($this->privateKey === '') {
            throw new InvalidArgumentException("Set federated-identity.providers.{$this->name}.private_key to sign Apple client secrets.");
        }

        if (! is_file($this->privateKey) || ! is_readable($this->privateKey)) {
            throw new InvalidArgumentException("The Apple private key [{$this->privateKey}] does not exist or is not readable.");
        }

        return (string) file_get_contents($this->privateKey);
    }
}
