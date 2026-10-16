<?php

namespace XLaravel\FederatedIdentity\Verifiers;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\HttpClientException;
use UnexpectedValueException;

class KeySet
{
    public function __construct(
        protected Http $http,
        protected Cache $cache,
        protected string $url,
        protected string $cacheKey,
        protected int $timeout = 10,
        protected int $ttl = 86400,
        protected int $refreshAfter = 60,
    ) {
    }

    /**
     * @return array<mixed>
     *
     * @throws HttpClientException
     * @throws UnexpectedValueException
     */
    public function get(?string $keyId = null): array
    {
        $cached = $this->cache->get($this->cacheKey);

        if (is_array($cached) && is_array($cached['keys'] ?? null) && is_int($cached['fetched_at'] ?? null)) {
            if ($keyId === null || $this->has($cached['keys'], $keyId) || now()->getTimestamp() - $cached['fetched_at'] < $this->refreshAfter) {
                return $cached['keys'];
            }
        }

        if ($this->cache->has($this->failureKey())) {
            throw new UnexpectedValueException("Fetching the signing keys from [{$this->url}] failed less than {$this->refreshAfter} seconds ago.");
        }

        try {
            return $this->fetch();
        } catch (HttpClientException|UnexpectedValueException $exception) {
            $this->cache->put($this->failureKey(), now()->getTimestamp(), $this->refreshAfter);

            throw $exception;
        }
    }

    public function forget(): void
    {
        $this->cache->forget($this->cacheKey);
        $this->cache->forget($this->failureKey());
    }

    /**
     * @return array<mixed>
     *
     * @throws HttpClientException
     * @throws UnexpectedValueException
     */
    protected function fetch(): array
    {
        $response = $this->http->timeout($this->timeout)->acceptJson()->get($this->url)->throw();
        $keys = $response->json();

        if (! is_array($keys) || ! is_array($keys['keys'] ?? null)) {
            throw new UnexpectedValueException("[{$this->url}] returned no signing keys.");
        }

        $this->cache->put($this->cacheKey, ['keys' => $keys, 'fetched_at' => now()->getTimestamp()], $this->lifetime($response->header('Cache-Control')));

        return $keys;
    }

    /**
     * @param  array<mixed>  $keys
     */
    protected function has(array $keys, string $keyId): bool
    {
        foreach (is_array($keys['keys'] ?? null) ? $keys['keys'] : [] as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $keyId) {
                return true;
            }
        }

        return false;
    }

    protected function lifetime(string $cacheControl): int
    {
        if (preg_match('/max-age=(\d+)/', $cacheControl, $matches) === 1 && (int) $matches[1] > 0) {
            return (int) $matches[1];
        }

        return $this->ttl;
    }

    protected function failureKey(): string
    {
        return "{$this->cacheKey}:failed";
    }
}
