<?php

namespace XLaravel\FederatedIdentity\Tests;

use XLaravel\FederatedIdentity\Contracts\TokenVerifier;
use XLaravel\FederatedIdentity\Exceptions\InvalidTokenException;
use XLaravel\FederatedIdentity\Facades\FederatedIdentity;
use XLaravel\FederatedIdentity\Providers\Apple\AppleTokenVerifier;
use XLaravel\FederatedIdentity\Tests\Concerns\IssuesTokens;

class AppleTokenVerifierTest extends TestCase
{
    use IssuesTokens;

    private const NONCE = 'raw-nonce-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeKeys(AppleTokenVerifier::KEYS_URL);
    }

    public function testVerifiesATokenForTheAppAndTheWebsite(): void
    {
        $user = $this->verifier()->verify($this->token($this->claims()), self::NONCE);

        $this->assertSame('apple', $user->provider);
        $this->assertSame('001234.abc', $user->id);
        $this->assertSame('com.example.app', $user->audience);
        $this->assertSame('ali@privaterelay.appleid.com', $user->email);
        $this->assertTrue($user->emailVerified);
        $this->assertNull($user->name());

        $web = $this->verifier()->verify($this->token($this->claims(['aud' => 'com.example.web'])), self::NONCE);

        $this->assertSame('com.example.web', $web->audience);
    }

    public function testRequiresANonce(): void
    {
        $this->expectExceptionObject(InvalidTokenException::nonce('apple'));

        $this->verifier()->verify($this->token($this->claims()));
    }

    public function testRejectsAnotherNonce(): void
    {
        $this->expectExceptionObject(InvalidTokenException::nonce('apple'));

        $this->verifier()->verify($this->token($this->claims()), 'another-nonce');
    }

    public function testRejectsTheHashedNonceSentAsTheRawOne(): void
    {
        $this->expectExceptionObject(InvalidTokenException::nonce('apple'));

        $this->verifier()->verify($this->token($this->claims()), hash('sha256', self::NONCE));
    }

    public function testRejectsATokenIssuedForAnotherClient(): void
    {
        $this->expectExceptionObject(InvalidTokenException::audience('apple'));

        $this->verifier()->verify($this->token($this->claims(['aud' => 'com.attacker.app'])), self::NONCE);
    }

    public function testRejectsAnotherIssuer(): void
    {
        $this->expectException(InvalidTokenException::class);

        $this->verifier()->verify($this->token($this->claims(['iss' => 'https://evil.example.com'])), self::NONCE);
    }

    public function testRejectsATokenSignedWithAnotherKey(): void
    {
        $this->expectException(InvalidTokenException::class);

        $this->verifier()->verify($this->token($this->claims(), 'key-1', 'attacker'), self::NONCE);
    }

    public function testKeepsTheNameFilledByTheClient(): void
    {
        $user = $this->verifier()->verify($this->token($this->claims()), self::NONCE)->withName('Ali', 'Veli');

        $this->assertSame('Ali Veli', $user->name());
        $this->assertSame('Ali', $user->withName('Ayşe', null)->givenName);
        $this->assertNull($this->verifier()->verify($this->token($this->claims()), self::NONCE)->withName('', null)->givenName);
    }

    public function testMessagesAreTranslated(): void
    {
        app()->setLocale('tr');

        $this->assertSame('Apple girişinin güvenlik kodu geçersiz.', InvalidTokenException::nonce('apple')->getMessage());
    }

    private function verifier(): TokenVerifier
    {
        return FederatedIdentity::verifier('apple');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        return [
            'iss' => 'https://appleid.apple.com',
            'aud' => 'com.example.app',
            'sub' => '001234.abc',
            'email' => 'ali@privaterelay.appleid.com',
            'email_verified' => 'true',
            'nonce' => hash('sha256', self::NONCE),
            'iat' => time(),
            'exp' => time() + 600,
            ...$overrides,
        ];
    }
}
