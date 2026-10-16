<?php

namespace XLaravel\FederatedIdentity;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use XLaravel\FederatedIdentity\Events\IdentityLinked;
use XLaravel\FederatedIdentity\Events\IdentityUnlinked;
use XLaravel\FederatedIdentity\Exceptions\AccountExistsException;
use XLaravel\FederatedIdentity\Exceptions\AlreadyLinkedException;
use XLaravel\FederatedIdentity\Exceptions\EmailMissingException;
use XLaravel\FederatedIdentity\Exceptions\IdentityTakenException;
use XLaravel\FederatedIdentity\Models\Identity;
use XLaravel\FederatedIdentity\Support\FederatedIdentityConfig;

class IdentityResolver
{
    public function __construct(
        protected UserProvider $users,
        protected bool $linkByEmail = true,
    ) {
    }

    public function find(ProviderUser $providerUser): ?Authenticatable
    {
        $identity = $this->identity($providerUser);

        return $identity !== null ? $this->users->retrieveById($identity->user_id) : null;
    }

    /**
     * @throws EmailMissingException
     * @throws AccountExistsException
     */
    public function match(ProviderUser $providerUser): ?Authenticatable
    {
        if ($providerUser->email === null) {
            throw EmailMissingException::for($providerUser->provider);
        }

        $user = $this->users->retrieveByCredentials(['email' => $providerUser->email]);

        if ($user === null) {
            return null;
        }

        if (! $this->linkByEmail
            || ! $providerUser->emailVerified
            || ! $this->hasVerifiedEmail($user)
            || $this->identityOf($user, $providerUser->provider) !== null) {
            throw AccountExistsException::for($providerUser->provider);
        }

        return $user;
    }

    /**
     * @throws IdentityTakenException
     * @throws AlreadyLinkedException
     */
    public function link(Authenticatable $user, ProviderUser $providerUser): Identity
    {
        $identity = $this->identity($providerUser);

        if ($identity !== null && $this->belongsTo($identity, $user)) {
            return $identity;
        }

        if ($identity !== null) {
            throw IdentityTakenException::for($providerUser->provider);
        }

        if ($this->identityOf($user, $providerUser->provider) !== null) {
            throw AlreadyLinkedException::for($providerUser->provider);
        }

        try {
            $identity = $this->identities()->getConnection()->transaction(fn () => $this->identities()->create([
                'user_id' => $user->getAuthIdentifier(),
                'provider' => $providerUser->provider,
                'provider_id' => $providerUser->id,
                'email' => $providerUser->email,
            ]));
        } catch (UniqueConstraintViolationException) {
            $identity = $this->identity($providerUser);

            if ($identity !== null && $this->belongsTo($identity, $user)) {
                return $identity;
            }

            throw $identity !== null
                ? IdentityTakenException::for($providerUser->provider)
                : AlreadyLinkedException::for($providerUser->provider);
        }

        event(new IdentityLinked($user, $identity));

        return $identity;
    }

    public function unlink(Authenticatable $user, string $provider): ?Identity
    {
        $identity = $this->identityOf($user, $provider);

        if ($identity === null) {
            return null;
        }

        $identity->delete();

        event(new IdentityUnlinked($user, $identity));

        return $identity;
    }

    public function owns(Authenticatable $user, ProviderUser $providerUser): bool
    {
        $identity = $this->identity($providerUser);

        return $identity !== null && $this->belongsTo($identity, $user);
    }

    public function identity(ProviderUser $providerUser): ?Identity
    {
        return $this->identities()
            ->where('provider', $providerUser->provider)
            ->where('provider_id', $providerUser->id)
            ->first();
    }

    /**
     * @return Builder<Identity>
     */
    protected function identities(): Builder
    {
        return FederatedIdentityConfig::identityModel()::query();
    }

    protected function identityOf(Authenticatable $user, string $provider): ?Identity
    {
        return $this->identities()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('provider', $provider)
            ->first();
    }

    protected function belongsTo(Identity $identity, Authenticatable $user): bool
    {
        $key = $user->getAuthIdentifier();

        return (is_int($key) || is_string($key)) && (string) $identity->user_id === (string) $key;
    }

    protected function hasVerifiedEmail(Authenticatable $user): bool
    {
        return $user instanceof MustVerifyEmail && $user->hasVerifiedEmail();
    }
}
