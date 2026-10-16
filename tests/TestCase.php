<?php

namespace XLaravel\FederatedIdentity\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use XLaravel\FederatedIdentity\Tests\Fixtures\User;
use XLaravel\FederatedIdentity\FederatedIdentityServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [FederatedIdentityServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('federated-identity.providers.google.client_ids', 'web.apps.googleusercontent.com, ios.apps.googleusercontent.com');
        $app['config']->set('federated-identity.providers.apple.client_ids', ['com.example.app', 'com.example.web']);
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
