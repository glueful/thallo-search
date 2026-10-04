<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

/**
 * A continuation position, not a snapshot (search block spec §3.4): how many raw candidates have
 * been examined, bound to the query that examined them.
 */
final class Cursor
{
    public function __construct(
        public readonly string $binding,
        public readonly int $rawOffset,
    ) {
    }
}
