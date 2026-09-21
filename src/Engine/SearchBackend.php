<?php

declare(strict_types=1);

namespace Thallo\Search\Engine;

use Thallo\Search\Query\SearchRequest;
use Thallo\Search\Query\SearchResults;

/**
 * Engine-neutral search port. Everything but the engines themselves depends only on this:
 * {@see PostgresFtsBackend}, {@see MeilisearchBackend}, and {@see UnavailableSearchBackend} when
 * neither can answer ({@see SearchEngineChoice}).
 */
interface SearchBackend
{
    /** What an operator is told is answering searches (`search:status`). */
    public function name(): string;

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
