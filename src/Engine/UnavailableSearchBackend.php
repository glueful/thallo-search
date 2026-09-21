<?php

declare(strict_types=1);

namespace Thallo\Search\Engine;

use Thallo\Search\Query\SearchRequest;
use Thallo\Search\Query\SearchResults;

/**
 * The backend when no engine can answer ({@see SearchEngineChoice}): a search fails closed (the
 * endpoint's 503), health is false, and writes are dropped — a publish must never fail because
 * search is misconfigured. The reason is what `search:status` prints.
 */
final class UnavailableSearchBackend implements SearchBackend
{
    public function __construct(private readonly string $reason)
    {
    }

    public function name(): string
    {
        return 'unavailable — ' . $this->reason;
    }

    public function ensureIndex(): void
    {
    }

    public function upsert(iterable $documents): void
    {
    }

    public function deleteEntry(string $entryUuid, ?string $locale = null): void
    {
    }

    public function search(SearchRequest $request): SearchResults
    {
        throw new \RuntimeException('Search is unavailable: ' . $this->reason);
    }

    public function health(): bool
    {
        return false;
    }
}
