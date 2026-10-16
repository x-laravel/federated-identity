<?php

namespace XLaravel\FederatedIdentity;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use XLaravel\FederatedIdentity\Support\FederatedIdentityConfig;

class FederatedIdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/federated-identity.php', 'federated-identity');

        $this->app->singleton(FederatedIdentityManager::class, fn (Application $app) => new FederatedIdentityManager($app));

        $this->app->bind(IdentityResolver::class, fn (Application $app) => new IdentityResolver(
            users: $this->userProvider($app),
            linkByEmail: Config::boolean('federated-identity.link_by_email', true),
        ));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'federated-identity');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/federated-identity.php' => $this->app->configPath('federated-identity.php'),
        ], 'federated-identity-config');

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'federated-identity-migrations');

        $this->publishes([
            __DIR__ . '/../lang' => $this->app->langPath('vendor/federated-identity'),
        ], 'federated-identity-translations');
    }

    protected function userProvider(Application $app): UserProvider
    {
        $name = FederatedIdentityConfig::userProvider();
        $provider = $app->make(AuthManager::class)->createUserProvider($name);

        if (! $provider instanceof UserProvider) {
            throw new InvalidArgumentException("The [{$name}] user provider is not defined in auth.providers.");
        }

        return $provider;
    }
}
