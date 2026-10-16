<?php

namespace XLaravel\FederatedIdentity\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use XLaravel\FederatedIdentity\Models\Identity;

final readonly class IdentityUnlinked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Authenticatable $user,
        public Identity $identity,
    ) {
    }
}
