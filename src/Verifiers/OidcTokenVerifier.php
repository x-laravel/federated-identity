<?php

namespace XLaravel\FederatedIdentity\Verifiers;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\HttpClientException;
use InvalidArgumentException;
use UnexpectedValueException;
use XLaravel\FederatedIdentity\Contracts\TokenVerifier;
use XLaravel\FederatedIdentity\Exceptions\InvalidTokenException;
use XLaravel\FederatedIdentity\Exceptions\ProviderUnavailableException;
use XLaravel\FederatedIdentity\ProviderUser;

class OidcTokenVerifier implements TokenVerifier
{
    /**
     * @param  list<string>  $issuers
     * @param  list<string>  $clientIds
     */
    public function __construct(
        protected string $name,
        protected string $label,
        protected KeySet $keys,
        protected array $issuers,
        protected array $clientIds,
        protected bool $requiresNonce = false,
        protected int $leeway = 0,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function requiresNonce(): bool
    {
        return $this->requiresNonce;
    }

    /**
     * @return list<string>
     */
    public function clientIds(): array
    {
        return $this->clientIds;
    }

    public function verify(string $idToken, ?string $nonce = null): ProviderUser
    {
        $claims = $this->decode($idToken);

        if (! in_array($claims['iss'] ?? null, $this->issuers, true)) {
            throw InvalidTokenException::unverified($this->name);
        }

        $audience = $claims['aud'] ?? null;

        if (! is_string($audience) || ! in_array($audience, $this->clientIds, true)) {
            throw InvalidTokenException::audience($this->name);
        }

        $subject = $claims['sub'] ?? null;

        if (! is_string($subject) || $subject === '') {
            throw InvalidTokenException::unverified($this->name);
        }

        $this->checkNonce($claims, $nonce);

        return $this->user($subject, $audience, $claims);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function user(string $subject, string $audience, array $claims): ProviderUser
    {
        return new ProviderUser(
            provider: $this->name,
            id: $subject,
            audience: $audience,
            email: $this->stringClaim($claims, 'email'),
            emailVerified: $this->emailVerified($claims),
            givenName: $this->stringClaim($claims, 'given_name'),
            familyName: $this->stringClaim($claims, 'family_name'),
            avatarUrl: $this->stringClaim($claims, 'picture'),
            claims: $claims,
        );
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function emailVerified(array $claims): bool
    {
        return $this->boolClaim($claims, 'email_verified');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function checkNonce(array $claims, ?string $nonce): void
    {
        if ($nonce === null && ! $this->requiresNonce) {
            return;
        }

        $claim = $claims['nonce'] ?? null;

        if ($nonce === null || ! is_string($claim) || ! hash_equals(hash('sha256', $nonce), $claim)) {
            throw InvalidTokenException::nonce($this->name);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(string $idToken): array
    {
        $keyId = $this->keyId($idToken);

        try {
            $keys = $this->keys->get($keyId);
        } catch (HttpClientException|UnexpectedValueException $exception) {
            throw ProviderUnavailableException::for($this->name, $exception);
        }

        $leeway = JWT::$leeway;
        JWT::$leeway = $this->leeway;

        try {
            $claims = JWT::decode($idToken, JWK::parseKeySet($keys, 'RS256'));
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException $exception) {
            throw InvalidTokenException::unverified($this->name, $exception);
        } finally {
            JWT::$leeway = $leeway;
        }

        /** @var array<string, mixed> */
        return json_decode((string) json_encode($claims), true);
    }

    protected function keyId(string $idToken): ?string
    {
        $segments = explode('.', $idToken);

        if (count($segments) !== 3) {
            throw InvalidTokenException::unverified($this->name);
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true);
        } catch (InvalidArgumentException $exception) {
            throw InvalidTokenException::unverified($this->name, $exception);
        }

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function stringClaim(array $claims, string $name): ?string
    {
        $value = $claims[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function boolClaim(array $claims, string $name): bool
    {
        return filter_var($claims[$name] ?? false, FILTER_VALIDATE_BOOL);
    }
}
