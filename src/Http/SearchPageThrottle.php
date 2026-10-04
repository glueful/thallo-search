<?php

declare(strict_types=1);

namespace Thallo\Search\Http;

use Glueful\Cache\CacheStore;

/**
 * The results page's own rate limit (search block spec §3.7): a fixed one-minute window per client.
 * The framework's limiter answers JSON, and a visitor deserves the themed page with `Retry-After`.
 */
final class SearchPageThrottle
{
    public function __construct(
        private readonly CacheStore $cache,
        private readonly int $perMinute,
    ) {
    }

    /** Count one request; false once the client is over its limit for this minute. */
    public function hit(string $client): bool
    {
        $key = 'search:page:' . sha1($client) . ':' . intdiv(time(), 60);
        $count = (int) ($this->cache->get($key) ?? 0) + 1;
        $this->cache->set($key, (string) $count, 60);
        return $count <= $this->perMinute;
    }

    public function secondsLeft(): int
    {
        return 60 - (time() % 60);
    }
}
