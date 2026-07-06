<?php

declare(strict_types=1);

namespace Thallo\Search\Engine;

use Thallo\Search\Index\DocumentBuilder;
use Thallo\Search\Query\Hit;
use Thallo\Search\Query\SearchRequest;
use Thallo\Search\Query\SearchResults;

/**
 * Content-aware SearchBackend over the MeilisearchIndex seam. Imports no meilisearch —
 * translation of SearchRequest → filter/params and hits → Hit lives here and is fully
 * fake-testable. Visibility is enforced INSIDE the filter (never post-filtered).
 */
final class MeilisearchBackend implements SearchBackend
{
    // Non-printable sentinels wrap highlights so we can HTML-escape everything else safely.
    private const HL_PRE = "\x02";
    private const HL_POST = "\x03";

    private const SEARCHABLE = ['title', 'body'];
    // entry_uuid is filterable so whole-entry purges (deleteByFilter) are valid in Meilisearch.
    private const FILTERABLE = ['content_type_uuid', 'content_type_slug', 'locale', 'entry_uuid'];

    public function __construct(
        private readonly MeilisearchIndex $index,
        private readonly int $snippetLength,
    ) {
    }

    public function ensureIndex(): void
    {
        $this->index->ensureIndex([
            'searchableAttributes' => self::SEARCHABLE,
            'filterableAttributes' => self::FILTERABLE,
        ]);
    }

    public function upsert(iterable $documents): void
    {
        $docs = is_array($documents) ? array_values($documents) : iterator_to_array($documents, false);
        if ($docs === []) {
            return;
        }
        $this->index->addDocuments($docs);
    }

    public function deleteEntry(string $entryUuid, ?string $locale = null): void
    {
        if ($locale !== null) {
            $this->index->deleteDocument(DocumentBuilder::documentId($entryUuid, $locale));
            return;
        }
        $this->index->deleteByFilter('entry_uuid = ' . $this->quote($entryUuid));
    }

    public function search(SearchRequest $request): SearchResults
    {
        // No all-access and nothing visible (no public types, no matching scopes): nothing
        // can match — skip Meilisearch entirely rather than sending an empty IN list.
        if (!$request->allAccess && $request->visibleTypeUuids === []) {
            return new SearchResults([], 0, $request->limit, $request->offset);
        }

        $params = [
            'limit' => $request->limit,
            'offset' => $request->offset,
            'filter' => $this->buildFilter($request),
            'attributesToRetrieve' => ['entry_uuid', 'content_type_slug', 'locale', 'href', 'title'],
            'attributesToHighlight' => ['body'],
            'highlightPreTag' => self::HL_PRE,
            'highlightPostTag' => self::HL_POST,
            'attributesToCrop' => ['body'],
            'cropLength' => $this->snippetLength,
            'cropMarker' => '…',
            'showRankingScore' => true,
        ];

        $raw = $this->index->rawSearch($request->q, $params);

        $hits = [];
        foreach ((array) ($raw['hits'] ?? []) as $row) {
            $formatted = (array) ($row['_formatted'] ?? []);
            $hits[] = new Hit(
                entryUuid: (string) ($row['entry_uuid'] ?? ''),
                contentTypeSlug: (string) ($row['content_type_slug'] ?? ''),
                locale: (string) ($row['locale'] ?? ''),
                href: (string) ($row['href'] ?? ''),
                title: (string) ($row['title'] ?? ''),
                snippet: $this->safeSnippet((string) ($formatted['body'] ?? ($row['body'] ?? ''))),
                score: (float) ($row['_rankingScore'] ?? 0.0),
            );
        }

        return new SearchResults(
            hits: $hits,
            total: (int) ($raw['estimatedTotalHits'] ?? count($hits)),
            limit: $request->limit,
            offset: $request->offset,
        );
    }

    public function health(): bool
    {
        return $this->index->reachable();
    }

    private function buildFilter(SearchRequest $request): string
    {
        $clauses = ['locale = ' . $this->quote($request->locale)];

        if (!$request->allAccess) {
            // Visibility comes from the LIVE type store (public + scope-granted uuids),
            // never from values denormalized into documents at index time.
            $ids = implode(', ', array_map([$this, 'quote'], $request->visibleTypeUuids));
            $clauses[] = 'content_type_uuid IN [' . $ids . ']';
        }

        if ($request->typeSlug !== null) {
            $clauses[] = 'content_type_slug = ' . $this->quote($request->typeSlug);
        }

        return implode(' AND ', $clauses);
    }

    /** HTML-escape everything except the highlight sentinels, which become <mark></mark>. */
    private function safeSnippet(string $formatted): string
    {
        $escaped = htmlspecialchars($formatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return str_replace(
            [self::HL_PRE, self::HL_POST],
            ['<mark>', '</mark>'],
            $escaped,
        );
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
