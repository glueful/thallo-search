<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

/** One raw window of hits, and the engine's count of every match (approximate after presentation). */
final class StoreResult
{
    /** @param list<StoreHit> $hits */
    public function __construct(
        public readonly array $hits,
        public readonly int $total,
    ) {
    }
}
