<?php

namespace XLaravel\FederatedIdentity\Tests;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use stdClass;
use XLaravel\FederatedIdentity\FederatedIdentityServiceProvider;
use XLaravel\FederatedIdentity\IdentityResolver;
use XLaravel\FederatedIdentity\Support\FederatedIdentityConfig;

class ServiceProviderTest extends TestCase
{
    public function testReadsListsFromArraysAndCommaSeparatedStrings(): void
    {
        $this->assertSame(['a', 'b'], FederatedIdentityConfig::list(' a, ,b '));
        $this->assertSame(['a', 'b'], FederatedIdentityConfig::list(['a', '', ' b', 5]));
        $this->assertSame([], FederatedIdentityConfig::list(null));
    }

    public function testReadsTheUserModelFromTheConfiguredUserProvider(): void
    {
        Config::set('auth.providers.members', ['driver' => 'eloquent', 'model' => Fixtures\User::class]);
        Config::set('federated-identity.provider', 'members');

        $this->assertSame(Fixtures\User::class, FederatedIdentityConfig::userModel());
        $this->assertInstanceOf(IdentityResolver::class, app()->make(IdentityResolver::class));
    }

    public function testRefusesAUserProviderWithoutAnEloquentModel(): void
    {
        Config::set('auth.providers.users.model', stdClass::class);

        $this->expectException(InvalidArgumentException::class);

        FederatedIdentityConfig::userModel();
    }

    public function testRefusesAnIdentityModelThatDoesNotExtendIdentity(): void
    {
        Config::set('federated-identity.model', Fixtures\User::class);

        $this->expectException(InvalidArgumentException::class);

        FederatedIdentityConfig::identityModel();
    }

    public function testRefusesAnUndefinedUserProvider(): void
    {
        Config::set('federated-identity.provider', 'missing');

        $this->expectExceptionMessage('[missing]');

        app()->make(IdentityResolver::class);
    }

    public function testRegistersThePublishableFiles(): void
    {
        foreach (['federated-identity-config', 'federated-identity-migrations', 'federated-identity-translations'] as $tag) {
            $this->assertNotEmpty(ServiceProvider::pathsToPublish(FederatedIdentityServiceProvider::class, $tag), $tag);
        }
    }
}
