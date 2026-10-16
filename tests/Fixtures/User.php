<?php

namespace XLaravel\FederatedIdentity\Tests\Fixtures;

use Illuminate\Auth\MustVerifyEmail as VerifiesEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use XLaravel\FederatedIdentity\Concerns\HasFederatedIdentities;

/**
 * @property int $id
 * @property string|null $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasFederatedIdentities;
    use VerifiesEmail;

    protected $table = 'users';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }
}
