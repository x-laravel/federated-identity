<?php

namespace XLaravel\FederatedIdentity\Tests\Fixtures;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;

class LowercaseUserProvider extends EloquentUserProvider
{
    public function __construct(Hasher $hasher)
    {
        parent::__construct($hasher, User::class);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if (is_string($credentials['email'] ?? null)) {
            $credentials['email'] = Str::lower($credentials['email']);
        }

        return parent::retrieveByCredentials($credentials);
    }
}
