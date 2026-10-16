<?php

namespace XLaravel\FederatedIdentity\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use XLaravel\FederatedIdentity\Models\Identity;
use XLaravel\FederatedIdentity\Support\FederatedIdentityConfig;

trait HasFederatedIdentities
{
    /**
     * @return HasMany<Identity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(FederatedIdentityConfig::identityModel(), 'user_id');
    }

    public function identity(string $provider): ?Identity
    {
        return $this->identities()->where('provider', $provider)->first();
    }

    public function hasIdentity(string $provider): bool
    {
        return $this->identities()->where('provider', $provider)->exists();
    }
}
