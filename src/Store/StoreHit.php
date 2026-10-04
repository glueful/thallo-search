<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

/** One matching document: which item it is, and how well it matched. Nothing here is displayed. */
final class StoreHit
{
    public function __construct(
        public readonly string $kind,
        public readonly string $sourceId,
        public readonly string $locale,
        public readonly ?string $subtype,
        public readonly float $score,
    ) {
    }
}
