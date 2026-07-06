<?php

declare(strict_types=1);

namespace Thallo\Search\Engine;

use Thallo\Search\Query\SearchRequest;
use Thallo\Search\Query\SearchResults;

/**
 * Engine-neutral search port. Every unit except the single Meilisearch-confined class
 * depends only on this, so a PostgresFtsBackend can plug in later untouched.
 */
interface SearchBackend
{
    /** Create the index if absent and apply settings (searchable/filterable). Idempotent. */
    public function ensureIndex(): void;

    /**
     * Upsert documents (replace by document id "{entryUuid}:{locale}").
     *
     * @param iterable<array<string,mixed>> $documents
     */
    public function upsert(iterable $documents): void;

    /**
     * locale != null → delete document id "{entryUuid}:{locale}".
     * locale == null → delete ALL documents whose entry_uuid == entryUuid (hard delete).
     */
    public function deleteEntry(string $entryUuid, ?string $locale = null): void;

    public function search(SearchRequest $request): SearchResults;

    /** True when the backend is reachable and the index exists. Drives the 503 + doctor. */
    public function health(): bool;
}
