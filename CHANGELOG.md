# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `FederatedIdentityManager` and the `FederatedIdentity` facade: `verifier()`, `has()`, `providers()`, `label()`,
  `keySet()`, `revoker()`, `appleClientSecret()` and `extend()` to register providers in code.
- `Verifiers\OidcTokenVerifier`: verifies an ID token against the provider's signing keys, issuer,
  expiry, a list of accepted client IDs and, when given or required, a SHA-256 hashed nonce. Any
  OpenID Connect provider can be configured with the `oidc` driver; its issuer and `jwks_uri` must
  use https.
- `Providers\Google\GoogleTokenVerifier` and `Providers\Apple\AppleTokenVerifier` built on it; Apple requires the
  nonce. Google's email is considered verified only for Gmail addresses and Google Workspace
  accounts, where Google is authoritative for it.
- `Verifiers\KeySet`: caches a provider's signing keys for as long as its `Cache-Control` allows and
  refetches them at most once per `cache.refresh_after` seconds when a token names an unknown key.
  A failed fetch waits as long before it is retried and throws `ProviderUnavailableException`.
- `IdentityResolver`: `find()` returns the user a provider account is linked to by `sub`;
  `match()` returns an existing account with the same email only when it is safe to link, that is
  when both sides verified the address. Neither writes; `link()` and `unlink()` do, and `owns()`
  and `identity()` read. Creating users is left to the application.
- Users are looked up through a user provider of `config/auth.php`, named by the `provider` option.
- `Providers\Apple\AppleTokenRevoker` and `Providers\Apple\AppleClientSecret` to revoke Sign in with Apple tokens,
  built by the manager from the named Apple provider's config.
- `Concerns\HasFederatedIdentities` model trait and `Models\Identity`, which the `model` option may
  replace with a model that extends it.
- `Events\IdentityLinked` and `Events\IdentityUnlinked`, dispatched after commit.
- Exceptions for every refusal, with Turkish and English messages.
- Publishable config, migration and translations.
