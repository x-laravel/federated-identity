<?php

use XLaravel\FederatedIdentity\Models\Identity;

return [

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Every provider your apps sign in with, by name. The name is what you
    | pass to FederatedIdentity::verifier() and what the identity table stores.
    |
    | client_ids  Every client ID your apps sign in with: web, iOS, Android.
    |             A token is accepted only when its audience is one of them.
    |             An array, or a comma-separated string.
    | label       Name shown in messages. Defaults to the driver's own label.
    |
    | Google and Apple need nothing more. Apple's team_id, key_id and
    | private_key are only needed to revoke tokens; private_key is a path
    | relative to the application root, an absolute path or the PEM itself.
    |
    | Any other OpenID Connect provider uses the "oidc" driver:
    |
    | issuer    The token's "iss" claim. A string, an array or a
    |           comma-separated string.
    | jwks_uri  Where the provider publishes its signing keys.
    | nonce     Require a nonce on every token.
    |
    |   'microsoft' => [
    |       'driver' => 'oidc',
    |       'issuer' => 'https://login.microsoftonline.com/{tenant-id}/v2.0',
    |       'jwks_uri' => 'https://login.microsoftonline.com/{tenant-id}/discovery/v2.0/keys',
    |       'client_ids' => env('FEDERATED_IDENTITY_MICROSOFT_CLIENT_IDS'),
    |   ],
    |
    | Providers that need custom logic are registered with
    | FederatedIdentity::extend() instead.
    |
    */

    'providers' => [

        'google' => [
            'client_ids' => env('FEDERATED_IDENTITY_GOOGLE_CLIENT_IDS', ''),
        ],

        'apple' => [
            'client_ids' => env('FEDERATED_IDENTITY_APPLE_CLIENT_IDS', ''),
            'team_id' => env('FEDERATED_IDENTITY_APPLE_TEAM_ID'),
            'key_id' => env('FEDERATED_IDENTITY_APPLE_KEY_ID'),
            'private_key' => env('FEDERATED_IDENTITY_APPLE_PRIVATE_KEY'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Clock Leeway
    |--------------------------------------------------------------------------
    |
    | Seconds of clock difference allowed when checking a token's issue and
    | expiry times.
    |
    */

    'leeway' => 60,

    /*
    |--------------------------------------------------------------------------
    | Account Linking
    |--------------------------------------------------------------------------
    |
    | link_by_email  When a provider account is not linked yet and an account
    |                with the same email exists, link them if both sides have
    |                verified the address. When disabled, such a sign-in is
    |                always refused with AccountExistsException.
    | provider       The user provider in config/auth.php that finds your users
    |                by their key and email address. Its model is the model
    |                the identity table belongs to.
    |
    */

    'link_by_email' => true,

    'provider' => 'users',

    /*
    |--------------------------------------------------------------------------
    | Identity Table
    |--------------------------------------------------------------------------
    |
    | connection  Database connection of the table. Null uses the default.
    | table       Name of the table that links provider accounts to users.
    | model       Eloquent model of the table. It must extend
    |             XLaravel\FederatedIdentity\Models\Identity.
    |
    */

    'connection' => null,

    'table' => 'user_identities',

    'model' => Identity::class,

    /*
    |--------------------------------------------------------------------------
    | Signing Keys
    |--------------------------------------------------------------------------
    |
    | Providers' public keys are cached for as long as their Cache-Control
    | header allows, or for "ttl" seconds when they send none. A token signed
    | with an unknown key refetches them at most once per "refresh_after"
    | seconds, and a failed fetch is not retried before that either.
    |
    */

    'cache' => [
        'store' => null,
        'prefix' => 'federated-identity',
        'ttl' => 86400,
        'refresh_after' => 60,
    ],

    'http' => [
        'timeout' => 10,
    ],

];
