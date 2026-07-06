<?php

declare(strict_types=1);

namespace Thallo\Search\Index;

use Thallo\Contracts\Search\ContentReindexer;

/** Bound when lemma.search is disabled: the reindex listener resolves this and no-ops. */
final class NullContentReindexer implements ContentReindexer
{
    public function reindexEntry(string $entryUuid, ?string $locale): void
    {
    }
}
