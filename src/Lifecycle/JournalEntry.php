<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

/** One reported change to an item of a kind, in journal order. */
final class JournalEntry
{
    public function __construct(
        public readonly string $kind,
        public readonly string $sourceId,
        public readonly int $seq,
        public readonly bool $resolved,
    ) {
    }
}
