<?php

namespace XLaravel\FederatedIdentity\Tests\Concerns;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use RuntimeException;

trait IssuesTokens
{
    /** @var array<string, string> */
    private static array $privateKeys = [];

    /** @var array<string, array<string, string>> */
    private static array $publicKeys = [];

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function token(array $claims, string $kid = 'key-1', ?string $signWith = null): string
    {
        return JWT::encode($claims, $this->privateKey($signWith ?? $kid), 'RS256', $kid);
    }

    /**
     * @param  list<string>  $kids
     * @return array{keys: list<array<string, string>>}
     */
    protected function jwks(array $kids = ['key-1']): array
    {
        return ['keys' => array_map(fn (string $kid) => $this->publicKey($kid), $kids)];
    }

    /**
     * @param  list<string>  $kids
     */
    protected function fakeKeys(string $url, array $kids = ['key-1']): void
    {
        Http::fake([$url => Http::response($this->jwks($kids), 200, ['Cache-Control' => 'public, max-age=3600'])]);
    }

    private function privateKey(string $kid): string
    {
        $this->generate($kid);

        return self::$privateKeys[$kid];
    }

    /**
     * @return array<string, string>
     */
    private function publicKey(string $kid): array
    {
        $this->generate($kid);

        return self::$publicKeys[$kid];
    }

    private function generate(string $kid): void
    {
        if (isset(self::$privateKeys[$kid])) {
            return;
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        if (! $key instanceof OpenSSLAsymmetricKey || ! openssl_pkey_export($key, $pem) || ! is_string($pem)) {
            throw new RuntimeException('Could not generate an RSA key.');
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details) || ! is_array($details['rsa'] ?? null)) {
            throw new RuntimeException('Could not read the RSA key.');
        }

        $encode = fn (mixed $value) => rtrim(strtr(base64_encode(is_string($value) ? $value : ''), '+/', '-_'), '=');

        self::$privateKeys[$kid] = $pem;
        self::$publicKeys[$kid] = [
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $encode($details['rsa']['n'] ?? null),
            'e' => $encode($details['rsa']['e'] ?? null),
        ];
    }
}
