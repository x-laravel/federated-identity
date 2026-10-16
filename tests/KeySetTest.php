<?php

namespace XLaravel\FederatedIdentity\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use XLaravel\FederatedIdentity\Exceptions\ProviderUnavailableException;
use XLaravel\FederatedIdentity\Facades\FederatedIdentity;
use XLaravel\FederatedIdentity\Providers\Google\GoogleTokenVerifier;
use XLaravel\FederatedIdentity\Tests\Concerns\IssuesTokens;

class KeySetTest extends TestCase
{
    use IssuesTokens;

    private string $mode = 'up';

    private int $requests = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([GoogleTokenVerifier::KEYS_URL => function () {
            $this->requests++;

            return match ($this->mode) {
                'down' => throw new ConnectionException('Connection refused.'),
                'error' => Http::response('', 500),
                'malformed' => Http::response('<html></html>'),
                default => Http::response($this->jwks(), 200, ['Cache-Control' => 'public, max-age=3600']),
            };
        }]);
    }

    #[DataProvider('failures')]
    public function testReportsAProviderThatCannotServeItsKeys(string $mode): void
    {
        $this->mode = $mode;

        try {
            $this->verify();
            $this->fail('Verified a token without signing keys.');
        } catch (ProviderUnavailableException $exception) {
            $this->assertSame('google', $exception->provider);
            $this->assertNotNull($exception->getPrevious());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function failures(): array
    {
        return [
            'unreachable' => ['down'],
            'server error' => ['error'],
            'malformed response' => ['malformed'],
        ];
    }

    public function testTranslatesTheMessage(): void
    {
        app()->setLocale('tr');

        $this->assertSame('Google ile giriş şu anda yapılamıyor. Lütfen biraz sonra tekrar deneyin.', ProviderUnavailableException::for('google')->getMessage());
    }

    public function testDoesNotRetryRightAfterAFailure(): void
    {
        $this->mode = 'down';

        $this->assertUnavailable(3);
        $this->assertSame(1, $this->requests);
    }

    public function testRetriesOnceTheWaitIsOver(): void
    {
        $this->mode = 'down';
        $this->assertUnavailable(1);

        $this->mode = 'up';
        $this->travel(61)->seconds();

        $this->assertSame('1098', $this->verify());
        $this->assertSame(2, $this->requests);
    }

    public function testKeepsUsingTheCachedKeysWhileTheProviderIsDown(): void
    {
        $this->verify();

        $this->mode = 'down';
        $this->travel(61)->seconds();

        $this->assertSame('1098', $this->verify());
        $this->assertSame(1, $this->requests);
    }

    public function testDoesNotRetryForUnknownKeysRightAfterAFailure(): void
    {
        $this->verify();

        $this->mode = 'down';
        $this->travel(61)->seconds();

        $this->assertUnavailable(3, 'unknown-key');
        $this->assertSame(2, $this->requests);
    }

    public function testForgettingTheKeysAllowsARetry(): void
    {
        $this->mode = 'down';
        $this->assertUnavailable(1);

        $this->mode = 'up';
        FederatedIdentity::keySet('google', GoogleTokenVerifier::KEYS_URL)->forget();

        $this->assertSame('1098', $this->verify());
        $this->assertSame(2, $this->requests);
    }

    private function assertUnavailable(int $times, string $kid = 'key-1'): void
    {
        for ($i = 0; $i < $times; $i++) {
            try {
                $this->verify($kid);
                $this->fail('Verified a token without signing keys.');
            } catch (ProviderUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function verify(string $kid = 'key-1'): string
    {
        return FederatedIdentity::verifier('google')->verify($this->token([
            'iss' => 'https://accounts.google.com',
            'aud' => 'web.apps.googleusercontent.com',
            'sub' => '1098',
            'iat' => time(),
            'exp' => time() + 600,
        ], $kid))->id;
    }
}
