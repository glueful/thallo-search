<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

use Thallo\Contracts\Search\KindFilter;

/**
 * One engine query (search block spec §3.4): the words, the locale (documents in it or in every
 * locale), each requested kind's visibility filter, and a raw window. `legacy` reads the old,
 * kind-less documents, as entries.
 */
final class StoreQuery
{
    /** @param array<string, KindFilter> $kinds */
    public function __construct(
        public readonly string $q,
        public readonly string $locale,
        public readonly array $kinds,
        public readonly int $limit,
        public readonly int $offset,
        public readonly bool $legacy,
    ) {
    }
}
