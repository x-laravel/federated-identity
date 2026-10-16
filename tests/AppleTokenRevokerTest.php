<?php

namespace XLaravel\FederatedIdentity\Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;
use XLaravel\FederatedIdentity\Facades\FederatedIdentity;
use XLaravel\FederatedIdentity\Providers\Apple\AppleTokenRevoker;

class AppleTokenRevokerTest extends TestCase
{
    private string $privateKey;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if (! $key instanceof OpenSSLAsymmetricKey || ! openssl_pkey_export($key, $pem) || ! is_string($pem)) {
            throw new RuntimeException('Could not generate an EC key.');
        }

        $details = openssl_pkey_get_details($key);

        $this->privateKey = $pem;
        $this->publicKey = is_array($details) && is_string($details['key'] ?? null) ? $details['key'] : '';

        Config::set('federated-identity.providers.apple.team_id', 'TEAM123');
        Config::set('federated-identity.providers.apple.key_id', 'KEY123');
        Config::set('federated-identity.providers.apple.private_key', $this->privateKey);
    }

    public function testSignsTheClientSecret(): void
    {
        $this->freezeSecond();

        $secret = FederatedIdentity::appleClientSecret('apple')->for('com.example.app');

        [$header] = explode('.', $secret);
        $header = json_decode(JWT::urlsafeB64Decode($header), true);
        $claims = (array) JWT::decode($secret, new Key($this->publicKey, 'ES256'));

        $this->assertIsArray($header);
        $this->assertSame('ES256', $header['alg']);
        $this->assertSame('KEY123', $header['kid']);
        $this->assertSame('TEAM123', $claims['iss']);
        $this->assertSame('com.example.app', $claims['sub']);
        $this->assertSame('https://appleid.apple.com', $claims['aud']);
        $this->assertSame(now()->getTimestamp() + 300, $claims['exp']);
    }

    public function testReadsThePrivateKeyFromAPathRelativeToTheApplication(): void
    {
        $path = app()->basePath('storage/apple-test.p8');
        file_put_contents($path, $this->privateKey);

        try {
            Config::set('federated-identity.providers.apple.private_key', 'storage/apple-test.p8');

            $this->assertNotSame('', FederatedIdentity::appleClientSecret('apple')->for('com.example.web'));
        } finally {
            unlink($path);
        }
    }

    public function testRefusesAMissingKeyFile(): void
    {
        Config::set('federated-identity.providers.apple.private_key', 'storage/missing.p8');

        $this->expectException(InvalidArgumentException::class);

        FederatedIdentity::appleClientSecret('apple')->for('com.example.app');
    }

    public function testRefusesAClientIdThatIsNotConfigured(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FederatedIdentity::appleClientSecret('apple')->for('com.attacker.app');
    }

    public function testRefusesToSignWithoutTheTeamAndKeyIds(): void
    {
        Config::set('federated-identity.providers.apple.team_id', null);

        $this->expectException(InvalidArgumentException::class);

        FederatedIdentity::appleClientSecret('apple')->for('com.example.app');
    }

    public function testNamesTheMissingOptionOfTheProvider(): void
    {
        Config::set('federated-identity.providers.apple-business', ['driver' => 'apple', 'client_ids' => 'com.example.business']);

        $this->expectExceptionMessage('federated-identity.providers.apple-business.team_id');

        FederatedIdentity::appleClientSecret('apple-business')->for('com.example.business');
    }

    public function testExchangesTheCodeAndRevokesTheRefreshToken(): void
    {
        Http::fake([
            AppleTokenRevoker::TOKEN_URL => Http::response(['refresh_token' => 'refresh-1']),
            AppleTokenRevoker::REVOKE_URL => Http::response(),
        ]);

        $this->revoker()->revoke('com.example.app', 'code-1');

        Http::assertSentInOrder([
            fn (Request $request) => $request->url() === AppleTokenRevoker::TOKEN_URL
                && $request['code'] === 'code-1'
                && $request['grant_type'] === 'authorization_code'
                && $request['client_id'] === 'com.example.app'
                && ! isset($request['redirect_uri']),
            fn (Request $request) => $request->url() === AppleTokenRevoker::REVOKE_URL
                && $request['token'] === 'refresh-1'
                && $request['token_type_hint'] === 'refresh_token',
        ]);
    }

    public function testSendsTheRedirectUriOfAWebSignIn(): void
    {
        Http::fake([
            AppleTokenRevoker::TOKEN_URL => Http::response(['refresh_token' => 'refresh-1']),
            AppleTokenRevoker::REVOKE_URL => Http::response(),
        ]);

        $this->revoker()->revoke('com.example.web', 'code-1', 'https://example.com/auth/apple');

        Http::assertSent(fn (Request $request) => $request->url() === AppleTokenRevoker::TOKEN_URL
            && $request['redirect_uri'] === 'https://example.com/auth/apple');
    }

    public function testFailsWhenAppleRejectsTheCode(): void
    {
        Http::fake([AppleTokenRevoker::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(RequestException::class);

        $this->revoker()->revoke('com.example.app', 'code-1');
    }

    public function testARenamedAppleProviderSignsWithItsOwnConfig(): void
    {
        Config::set('federated-identity.providers.apple-business', [
            'driver' => 'apple',
            'client_ids' => 'com.example.business',
            'team_id' => 'TEAM456',
            'key_id' => 'KEY456',
            'private_key' => $this->privateKey,
        ]);

        $claims = (array) JWT::decode(FederatedIdentity::appleClientSecret('apple-business')->for('com.example.business'), new Key($this->publicKey, 'ES256'));

        $this->assertSame('TEAM456', $claims['iss']);
        $this->assertSame('com.example.business', $claims['sub']);

        $this->expectException(InvalidArgumentException::class);

        FederatedIdentity::appleClientSecret('apple')->for('com.example.business');
    }

    public function testRefusesToRevokeForAProviderThatIsNotApple(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FederatedIdentity::revoker('google');
    }

    public function testRefusesToRevokeForAProviderThatIsNotConfigured(): void
    {
        Config::set('federated-identity.providers', ['google' => ['client_ids' => 'web.apps.googleusercontent.com']]);

        $this->expectException(InvalidArgumentException::class);

        FederatedIdentity::revoker('apple');
    }

    private function revoker(): AppleTokenRevoker
    {
        return FederatedIdentity::revoker('apple');
    }
}
