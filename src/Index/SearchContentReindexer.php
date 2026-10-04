<?php

declare(strict_types=1);

namespace Thallo\Search\Index;

use Thallo\Contracts\Search\ContentReindexer;
use Thallo\Contracts\Search\SearchIndex;
use Thallo\Search\Sources\EntriesContributor;

/**
 * The core's after-commit entry events, as journaled search changes (search block spec §3.5.2). The
 * locale does not matter to the journal: the entries contributor re-reads every published locale
 * when the change is applied.
 */
final class SearchContentReindexer implements ContentReindexer
{
    public function __construct(
        private readonly SearchIndex $index,
    ) {
    }

    public function reindexEntry(string $entryUuid, ?string $locale): void
    {
        $this->index->changed(EntriesContributor::KIND, $entryUuid);
    }
}
