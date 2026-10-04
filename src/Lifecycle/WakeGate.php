<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Glueful\Cache\CacheStore;

/**
 * At most one queued drain per workspace and kind (search block spec §3.5.2): a live change claims
 * the gate before queueing a wake-up, and the drain job reopens it as it starts, so a bulk write of
 * thousands of changes queues one job, and a change after the job has begun queues the next. The
 * claim expires on its own, so a lost job never closes the gate for good.
 */
final class WakeGate
{
    private const TTL_SECONDS = 120;

    public function __construct(private readonly CacheStore $cache)
    {
    }

    /** True when this caller should queue the wake-up; false when one is already queued. */
    public function claim(?string $workspace, string $kind): bool
    {
        $key = self::key($workspace, $kind);
        if ($this->cache->get($key) !== null) {
            return false;
        }
        $this->cache->set($key, '1', self::TTL_SECONDS);
        return true;
    }

    public function release(?string $workspace, string $kind): void
    {
        $this->cache->delete(self::key($workspace, $kind));
    }

    private static function key(?string $workspace, string $kind): string
    {
        return 'search:wake:' . ($workspace ?? '-') . ':' . $kind;
    }
}
