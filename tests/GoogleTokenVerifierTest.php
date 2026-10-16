<?php

namespace XLaravel\FederatedIdentity\Tests;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use XLaravel\FederatedIdentity\Contracts\TokenVerifier;
use XLaravel\FederatedIdentity\Exceptions\InvalidTokenException;
use XLaravel\FederatedIdentity\Facades\FederatedIdentity;
use XLaravel\FederatedIdentity\Providers\Google\GoogleTokenVerifier;
use XLaravel\FederatedIdentity\Tests\Concerns\IssuesTokens;

class GoogleTokenVerifierTest extends TestCase
{
    use IssuesTokens;

    /** @var list<string> */
    private array $publishedKeys = ['key-1'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([GoogleTokenVerifier::KEYS_URL => fn () => Http::response($this->jwks($this->publishedKeys), 200, ['Cache-Control' => 'public, max-age=3600'])]);
    }

    public function testVerifiesATokenIssuedForAnyConfiguredClient(): void
    {
        $user = $this->verifier()->verify($this->token($this->claims()));

        $this->assertSame('google', $user->provider);
        $this->assertSame('1098', $user->id);
        $this->assertSame('web.apps.googleusercontent.com', $user->audience);
        $this->assertSame('ali@gmail.com', $user->email);
        $this->assertTrue($user->emailVerified);
        $this->assertSame('Ali', $user->givenName);
        $this->assertSame('Veli', $user->familyName);
        $this->assertSame('Ali Veli', $user->name());
        $this->assertSame('https://example.com/ali.jpg', $user->avatarUrl);

        $ios = $this->verifier()->verify($this->token($this->claims(['aud' => 'ios.apps.googleusercontent.com'])));

        $this->assertSame('ios.apps.googleusercontent.com', $ios->audience);
    }

    public function testAcceptsTheIssuerWithoutAScheme(): void
    {
        $user = $this->verifier()->verify($this->token($this->claims(['iss' => 'accounts.google.com'])));

        $this->assertSame('1098', $user->id);
    }

    public function testReadsAStringEmailVerifiedClaim(): void
    {
        $user = $this->verifier()->verify($this->token($this->claims(['email_verified' => 'false'])));

        $this->assertFalse($user->emailVerified);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    #[DataProvider('emailAuthority')]
    public function testTrustsTheEmailOnlyWhereGoogleIsAuthoritative(array $claims, bool $verified): void
    {
        $user = $this->verifier()->verify($this->token($this->claims($claims)));

        $this->assertSame($verified, $user->emailVerified);
        $this->assertSame($claims['email_verified'] ?? true, $user->claims['email_verified']);
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function emailAuthority(): array
    {
        return [
            'gmail' => [['email' => 'Ali@GMAIL.com'], true],
            'workspace' => [['email' => 'ali@example.com', 'hd' => 'example.com'], true],
            'other address' => [['email' => 'ali@example.com'], false],
            'unverified workspace' => [['email' => 'ali@example.com', 'hd' => 'example.com', 'email_verified' => false], false],
        ];
    }

    public function testRejectsATokenIssuedForAnotherClient(): void
    {
        $this->expectExceptionObject(InvalidTokenException::audience('google'));

        $this->verifier()->verify($this->token($this->claims(['aud' => 'other.apps.googleusercontent.com'])));
    }

    public function testRejectsAnotherIssuer(): void
    {
        $this->expectException(InvalidTokenException::class);

        $this->verifier()->verify($this->token($this->claims(['iss' => 'https://evil.example.com'])));
    }

    public function testRejectsAnExpiredToken(): void
    {
        $this->expectException(InvalidTokenException::class);

        $this->verifier()->verify($this->token($this->claims(['exp' => time() - 120])));
    }

    public function testRejectsATokenSignedWithAnotherKey(): void
    {
        $this->expectException(InvalidTokenException::class);

        $this->verifier()->verify($this->token($this->claims(), 'key-1', 'attacker'));
    }

    public function testRejectsAMalformedToken(): void
    {
        foreach (['', 'abc', 'abc.def.ghi', '***.***.***'] as $token) {
            try {
                $this->verifier()->verify($token);
                $this->fail("Accepted [{$token}].");
            } catch (InvalidTokenException $exception) {
                $this->assertSame('google', $exception->provider);
            }
        }
    }

    public function testRejectsATokenWithoutASubject(): void
    {
        $this->expectException(InvalidTokenException::class);

        $this->verifier()->verify($this->token($this->claims(['sub' => ''])));
    }

    public function testDoesNotRequireANonce(): void
    {
        $this->assertSame('1098', $this->verifier()->verify($this->token($this->claims()))->id);
    }

    public function testChecksANonceWhenOneIsGiven(): void
    {
        $token = $this->token($this->claims(['nonce' => hash('sha256', 'raw-nonce')]));

        $this->assertSame('1098', $this->verifier()->verify($token, 'raw-nonce')->id);

        $this->expectExceptionObject(InvalidTokenException::nonce('google'));

        $this->verifier()->verify($token, 'other-nonce');
    }

    public function testAllowsClockSkewWithinTheLeeway(): void
    {
        $token = $this->token($this->claims(['iat' => time() + 30, 'nbf' => time() + 30]));

        $this->assertSame('1098', $this->verifier()->verify($token)->id);
        $this->assertSame(0, JWT::$leeway);
    }

    public function testRejectsClockSkewBeyondTheLeeway(): void
    {
        Config::set('federated-identity.leeway', 0);

        $this->expectException(InvalidTokenException::class);

        $this->verifier()->verify($this->token($this->claims(['iat' => time() + 30, 'nbf' => time() + 30])));
    }

    public function testCachesTheSigningKeys(): void
    {
        $this->verifier()->verify($this->token($this->claims()));
        $this->verifier()->verify($this->token($this->claims()));

        Http::assertSentCount(1);
    }

    public function testRefetchesTheKeysWhenAnUnknownKeySignedTheToken(): void
    {
        $this->verifier()->verify($this->token($this->claims()));

        $this->publishedKeys = ['key-1', 'key-2'];
        $this->travel(61)->seconds();

        $this->assertSame('1098', $this->verifier()->verify($this->token($this->claims(), 'key-2'))->id);
        Http::assertSentCount(2);
    }

    public function testDoesNotRefetchTheKeysAgainRightAway(): void
    {
        $this->verifier()->verify($this->token($this->claims()));

        $this->publishedKeys = ['key-1', 'key-2'];

        try {
            $this->verifier()->verify($this->token($this->claims(), 'key-2'));
            $this->fail('Refetched the keys within refresh_after.');
        } catch (InvalidTokenException) {
            Http::assertSentCount(1);
        }
    }

    public function testResolvesThroughTheProvider(): void
    {
        $this->assertInstanceOf(GoogleTokenVerifier::class, FederatedIdentity::verifier('google'));
        $this->assertSame('google', FederatedIdentity::verifier('google')->name());
    }

    private function verifier(): TokenVerifier
    {
        return FederatedIdentity::verifier('google');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => 'web.apps.googleusercontent.com',
            'sub' => '1098',
            'email' => 'ali@gmail.com',
            'email_verified' => true,
            'given_name' => 'Ali',
            'family_name' => 'Veli',
            'picture' => 'https://example.com/ali.jpg',
            'iat' => time(),
            'exp' => time() + 600,
            ...$overrides,
        ];
    }
}
