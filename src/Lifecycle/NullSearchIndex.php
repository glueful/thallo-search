<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Thallo\Contracts\Search\SearchIndex;

/** Bound while search is off: changes are not journaled (the reconcile on re-enable rebuilds). */
final class NullSearchIndex implements SearchIndex
{
    public function changed(string $kind, string $sourceId): void
    {
    }

    public function kindChanged(string $kind, string $reason): void
    {
    }
}
