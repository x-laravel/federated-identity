<?php

namespace XLaravel\FederatedIdentity\Facades;

use Illuminate\Support\Facades\Facade;
use XLaravel\FederatedIdentity\FederatedIdentityManager;

/**
 * @method static \XLaravel\FederatedIdentity\Contracts\TokenVerifier verifier(string $name)
 * @method static bool has(string $name)
 * @method static list<string> providers()
 * @method static string label(string $name)
 * @method static \XLaravel\FederatedIdentity\Providers\Apple\AppleTokenRevoker revoker(string $name)
 * @method static \XLaravel\FederatedIdentity\Providers\Apple\AppleClientSecret appleClientSecret(string $name)
 * @method static \XLaravel\FederatedIdentity\Verifiers\KeySet keySet(string $name, string $url)
 * @method static FederatedIdentityManager extend(string $driver, \Closure $callback)
 * @method static FederatedIdentityManager forgetDrivers()
 *
 * @see FederatedIdentityManager
 */
class FederatedIdentity extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FederatedIdentityManager::class;
    }
}
