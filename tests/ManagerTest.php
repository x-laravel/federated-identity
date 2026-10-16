<?php

namespace XLaravel\FederatedIdentity\Tests;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use XLaravel\FederatedIdentity\Exceptions\AccountExistsException;
use XLaravel\FederatedIdentity\Exceptions\InvalidTokenException;
use XLaravel\FederatedIdentity\Facades\FederatedIdentity;
use XLaravel\FederatedIdentity\Providers\Apple\AppleTokenVerifier;
use XLaravel\FederatedIdentity\Providers\Google\GoogleTokenVerifier;
use XLaravel\FederatedIdentity\Support\FederatedIdentityConfig;
use XLaravel\FederatedIdentity\Tests\Concerns\IssuesTokens;
use XLaravel\FederatedIdentity\Verifiers\OidcTokenVerifier;

class ManagerTest extends TestCase
{
    use IssuesTokens;

    private const ISSUER = 'https://login.example.com/tenant/v2.0';

    private const JWKS_URI = 'https://login.example.com/tenant/keys';

    public function testResolvesTheBuiltInProviders(): void
    {
        $this->assertInstanceOf(GoogleTokenVerifier::class, FederatedIdentity::verifier('google'));
        $this->assertInstanceOf(AppleTokenVerifier::class, FederatedIdentity::verifier('apple'));
        $this->assertTrue(FederatedIdentity::verifier('apple')->requiresNonce());
        $this->assertFalse(FederatedIdentity::verifier('google')->requiresNonce());
        $this->assertSame(['google', 'apple'], FederatedIdentity::providers());
    }

    public function testRefusesAProviderThatIsNotConfigured(): void
    {
        $this->assertFalse(FederatedIdentity::has('github'));

        $this->expectException(InvalidArgumentException::class);

        FederatedIdentity::verifier('github');
    }

    public function testRefusesAProviderWithoutADriver(): void
    {
        Config::set('federated-identity.providers.acme', ['client_ids' => 'acme-web']);

        $this->expectException(InvalidArgumentException::class);

        FederatedIdentity::verifier('acme');
    }

    public function testRefusesAnOidcProviderWithoutIssuerOrKeys(): void
    {
        Config::set('federated-identity.providers.acme', ['driver' => 'oidc', 'issuer' => self::ISSUER]);

        $this->expectException(InvalidArgumentException::class);

        FederatedIdentity::verifier('acme');
    }

    /**
     * @param  array<string, string>  $override
     */
    #[DataProvider('insecureUrls')]
    public function testRefusesAnOidcProviderWithoutHttps(array $override): void
    {
        $this->configureMicrosoft($override);

        $this->expectExceptionMessage('must use https');

        FederatedIdentity::verifier('microsoft');
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function insecureUrls(): array
    {
        return [
            'issuer' => [['issuer' => 'http://login.example.com/tenant/v2.0']],
            'jwks_uri' => [['jwks_uri' => 'http://login.example.com/tenant/keys']],
        ];
    }

    public function testVerifiesATokenOfAConfiguredOidcProvider(): void
    {
        $this->configureMicrosoft();
        $this->fakeKeys(self::JWKS_URI);

        $user = FederatedIdentity::verifier('microsoft')->verify($this->token($this->claims()));

        $this->assertSame('microsoft', $user->provider);
        $this->assertSame('ms-user', $user->id);
        $this->assertSame('ali@example.com', $user->email);
        $this->assertSame('Microsoft', FederatedIdentity::label('microsoft'));
    }

    public function testAnOidcProviderChecksItsIssuerAndAudience(): void
    {
        $this->configureMicrosoft();
        $this->fakeKeys(self::JWKS_URI);

        foreach ([['iss' => 'https://accounts.google.com'], ['aud' => 'web.apps.googleusercontent.com']] as $override) {
            try {
                FederatedIdentity::verifier('microsoft')->verify($this->token($this->claims($override)));
                $this->fail('Accepted ' . json_encode($override) . '.');
            } catch (InvalidTokenException $exception) {
                $this->assertSame('microsoft', $exception->provider);
            }
        }
    }

    public function testAnOidcProviderCanRequireANonce(): void
    {
        $this->configureMicrosoft(['nonce' => true]);
        $this->fakeKeys(self::JWKS_URI);

        $this->expectExceptionObject(InvalidTokenException::nonce('microsoft'));

        FederatedIdentity::verifier('microsoft')->verify($this->token($this->claims()));
    }

    public function testEachProviderCachesItsOwnKeys(): void
    {
        $this->configureMicrosoft();
        Http::fake([
            self::JWKS_URI => Http::response($this->jwks(['ms-key'])),
            GoogleTokenVerifier::KEYS_URL => Http::response($this->jwks(['google-key'])),
        ]);

        FederatedIdentity::verifier('microsoft')->verify($this->token($this->claims(), 'ms-key'));

        $this->expectException(InvalidTokenException::class);

        FederatedIdentity::verifier('google')->verify($this->token([...$this->claims(), 'iss' => 'https://accounts.google.com', 'aud' => 'web.apps.googleusercontent.com'], 'ms-key'));
    }

    public function testRegistersACustomProvider(): void
    {
        Config::set('federated-identity.providers.line', ['client_ids' => 'line-channel']);
        $jwksUri = self::JWKS_URI;

        FederatedIdentity::extend('line', fn (Container $app, array $config, string $name) => new OidcTokenVerifier(
            name: $name,
            label: 'LINE',
            keys: FederatedIdentity::keySet($name, $jwksUri),
            issuers: ['https://access.line.me'],
            clientIds: FederatedIdentityConfig::list($config['client_ids'] ?? null),
        ));
        $this->fakeKeys(self::JWKS_URI);

        $user = FederatedIdentity::verifier('line')->verify($this->token($this->claims(['iss' => 'https://access.line.me', 'aud' => 'line-channel'])));

        $this->assertSame('line', $user->provider);
        $this->assertTrue(FederatedIdentity::has('line'));
        $this->assertSame('LINE', FederatedIdentity::label('line'));
        $this->assertSame('Bu e-posta adresiyle kayıtlı bir hesap var. Önce hesabınıza giriş yapın, ardından LINE hesabınızı bağlayın.', $this->turkish(fn () => AccountExistsException::for('line')->getMessage()));
    }

    public function testRefusesACustomProviderThatIsNotAVerifier(): void
    {
        FederatedIdentity::extend('broken', fn () => new stdClass);

        $this->expectException(InvalidArgumentException::class);

        FederatedIdentity::verifier('broken');
    }

    public function testLabelsAnUnknownProviderByItsName(): void
    {
        $this->assertSame('Yahoo Japan', FederatedIdentity::label('yahoo-japan'));
    }

    public function testRenamesABuiltInProvider(): void
    {
        Config::set('federated-identity.providers.google-workspace', ['driver' => 'google', 'client_ids' => 'web.apps.googleusercontent.com', 'label' => 'Google Workspace']);

        $verifier = FederatedIdentity::verifier('google-workspace');

        $this->assertInstanceOf(GoogleTokenVerifier::class, $verifier);
        $this->assertSame('google-workspace', $verifier->name());
        $this->assertSame('Google Workspace', $verifier->label());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureMicrosoft(array $overrides = []): void
    {
        Config::set('federated-identity.providers.microsoft', [
            'driver' => 'oidc',
            'issuer' => self::ISSUER,
            'jwks_uri' => self::JWKS_URI,
            'client_ids' => 'ms-client',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        return [
            'iss' => self::ISSUER,
            'aud' => 'ms-client',
            'sub' => 'ms-user',
            'email' => 'ali@example.com',
            'iat' => time(),
            'exp' => time() + 600,
            ...$overrides,
        ];
    }

    private function turkish(callable $callback): mixed
    {
        app()->setLocale('tr');

        return $callback();
    }
}
