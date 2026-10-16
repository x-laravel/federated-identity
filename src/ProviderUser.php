<?php

namespace XLaravel\FederatedIdentity;

final readonly class ProviderUser
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public string $provider,
        public string $id,
        public string $audience,
        public ?string $email = null,
        public bool $emailVerified = false,
        public ?string $givenName = null,
        public ?string $familyName = null,
        public ?string $avatarUrl = null,
        public array $claims = [],
    ) {
    }

    public function name(): ?string
    {
        $name = trim(($this->givenName ?? '') . ' ' . ($this->familyName ?? ''));

        return $name !== '' ? $name : null;
    }

    public function withName(?string $givenName, ?string $familyName): self
    {
        return new self(
            $this->provider,
            $this->id,
            $this->audience,
            $this->email,
            $this->emailVerified,
            $this->givenName ?? ($givenName ?: null),
            $this->familyName ?? ($familyName ?: null),
            $this->avatarUrl,
            $this->claims,
        );
    }
}
