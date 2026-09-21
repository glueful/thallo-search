<?php

declare(strict_types=1);

namespace Thallo\Search\Engine;

use Glueful\Database\Connection;
use Thallo\Search\Query\Hit;
use Thallo\Search\Query\SearchRequest;
use Thallo\Search\Query\SearchResults;

/**
 * Search over Postgres full-text search: nothing to install, nothing to run — the database every
 * Thallo site already has. The same contract as {@see MeilisearchBackend}: documents by
 * entry+locale, visibility enforced INSIDE the query (never post-filtered, so totals are true),
 * and a highlighted snippet that is safe to emit.
 *
 * The index is the `search_documents` table. Postgres keeps its `tsv` column itself (a generated
 * column: title weighted above body, in the row's own text-search configuration), so every write
 * here is a plain row write through the query builder — workspace scoping and the write barrier
 * apply as they do to any table, and no SQL is assembled from a document.
 *
 * A query is WORDS and nothing else. What the visitor typed is cut into words; each word matches
 * by its stem in the row's language OR as a prefix of a word as written, and every word must
 * match — so a search box finds "theming" from "them", "installing" from "install", and more
 * words narrow a search. No operator a visitor types reaches the query parser: a word is bound
 * as a value, never written into SQL.
 */
final class PostgresFtsBackend implements SearchBackend
{
    // Non-printable sentinels wrap highlights so everything else can be HTML-escaped safely.
    private const HL_PRE = "\x02";
    private const HL_POST = "\x03";
    private const TABLE = 'search_documents';
    private const MAX_WORDS = 12;

    /** Language subtag → a text-search configuration Postgres ships; anything else is `simple`. */
    private const CONFIGS = [
        'ar' => 'arabic', 'da' => 'danish', 'nl' => 'dutch', 'en' => 'english', 'fi' => 'finnish',
        'fr' => 'french', 'de' => 'german', 'el' => 'greek', 'hu' => 'hungarian', 'id' => 'indonesian',
        'ga' => 'irish', 'it' => 'italian', 'lt' => 'lithuanian', 'ne' => 'nepali', 'nb' => 'norwegian',
        'nn' => 'norwegian', 'no' => 'norwegian', 'pt' => 'portuguese', 'ro' => 'romanian',
        'ru' => 'russian', 'es' => 'spanish', 'sv' => 'swedish', 'ta' => 'tamil', 'tr' => 'turkish',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly int $snippetLength,
    ) {
    }

    public function name(): string
    {
        return 'Postgres full-text search';
    }

    public function ensureIndex(): void
    {
        // The table and its index are the pack's migration; there is nothing to create at runtime.
    }

    public function upsert(iterable $documents): void
    {
        foreach ($documents as $document) {
            $id = (string) ($document['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $row = [
                'doc_id' => $id,
                'entry_uuid' => (string) ($document['entry_uuid'] ?? ''),
                'locale' => (string) ($document['locale'] ?? ''),
                'content_type_uuid' => (string) ($document['content_type_uuid'] ?? ''),
                'content_type_slug' => (string) ($document['content_type_slug'] ?? ''),
                'href' => (string) ($document['href'] ?? ''),
                'title' => (string) ($document['title'] ?? ''),
                'body' => (string) ($document['body'] ?? ''),
                'ts_config' => self::configFor((string) ($document['locale'] ?? '')),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ];
            $this->db->table(self::TABLE)->transaction(function () use ($id, $row): void {
                $this->db->table(self::TABLE)->where('doc_id', '=', $id)->delete();
                $this->db->table(self::TABLE)->insert($row);
            });
        }
    }

    public function deleteEntry(string $entryUuid, ?string $locale = null): void
    {
        $query = $this->db->table(self::TABLE)->where('entry_uuid', '=', $entryUuid);
        if ($locale !== null) {
            $query->where('locale', '=', $locale);
        }
        $query->delete();
    }

    public function search(SearchRequest $request): SearchResults
    {
        $empty = new SearchResults([], 0, $request->limit, $request->offset);
        if (!$request->allAccess && $request->visibleTypeUuids === []) {
            return $empty;
        }
        $words = self::words($request->q);
        if ($words === []) {
            return $empty;
        }
        $config = self::configFor($request->locale);
        // One tsquery, used three times (match, rank, headline): per word, its stem in the row's
        // language OR the word as a prefix; all words AND-ed. Words are BOUND, never inlined.
        $perWord = "(plainto_tsquery(?::regconfig, ?) || to_tsquery('simple', ?))";
        $tsquery = '(' . implode(' && ', array_fill(0, count($words), $perWord)) . ')';
        $terms = [];
        foreach ($words as $word) {
            // A single letter is a word, not the start of one: "a" as a prefix is every page.
            array_push($terms, $config, $word, mb_strlen($word) > 1 ? $word . ':*' : $word);
        }
        $match = 'tsv @@ ' . $tsquery;

        $scoped = function () use ($request, $match, $terms) {
            $query = $this->db->table(self::TABLE)
                ->where('locale', '=', $request->locale)
                ->whereRaw($match, $terms);
            if (!$request->allAccess) {
                $query->whereIn('content_type_uuid', $request->visibleTypeUuids);
            }
            if ($request->typeSlug !== null) {
                $query->where('content_type_slug', '=', $request->typeSlug);
            }
            return $query;
        };

        $total = $scoped()->count();
        if ($total === 0) {
            return $empty;
        }

        $headline = sprintf(
            'StartSel=%s, StopSel=%s, MaxWords=%d, MinWords=%d, MaxFragments=1, ShortWord=2',
            self::HL_PRE,
            self::HL_POST,
            max(8, $this->snippetLength),
            max(4, intdiv($this->snippetLength, 2)),
        );
        $rows = $scoped()
            ->select(['entry_uuid', 'content_type_slug', 'locale', 'href', 'title'])
            // Normalisation 32 scales the rank into 0..1, as the API's `score` is.
            ->selectRaw("ts_rank_cd(tsv, {$tsquery}, 32) AS score", $terms)
            ->selectRaw(
                "ts_headline(?::regconfig, body, {$tsquery}, ?) AS snippet",
                [$config, ...$terms, $headline],
            )
            ->orderByRaw('score DESC, id ASC')
            ->limit($request->limit)
            ->offset($request->offset)
            ->get();

        $hits = [];
        foreach ($rows as $row) {
            $hits[] = new Hit(
                entryUuid: (string) $row['entry_uuid'],
                contentTypeSlug: (string) $row['content_type_slug'],
                locale: (string) $row['locale'],
                href: (string) $row['href'],
                title: (string) $row['title'],
                snippet: self::safeSnippet((string) ($row['snippet'] ?? '')),
                score: (float) ($row['score'] ?? 0.0),
            );
        }
        return new SearchResults($hits, $total, $request->limit, $request->offset);
    }

    public function health(): bool
    {
        try {
            return $this->db->getDriverName() === 'pgsql'
                && $this->db->getSchemaBuilder()->hasColumn(self::TABLE, 'tsv');
        } catch (\Throwable) {
            return false;
        }
    }

    /** `fr-CA` → `french`; a language Postgres has no configuration for → `simple` (no stemming). */
    public static function configFor(string $locale): string
    {
        $language = strtolower((string) preg_split('/[-_]/', $locale)[0]);
        return self::CONFIGS[$language] ?? 'simple';
    }

    /**
     * What the visitor typed, as words: only letters and digits survive, so nothing typed is ever
     * an operator; repeated words count once; a very long query is cut off.
     *
     * @return list<string>
     */
    private static function words(string $q): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }

    /** HTML-escape everything except the highlight sentinels, which become <mark></mark>. */
    private static function safeSnippet(string $headline): string
    {
        $escaped = htmlspecialchars($headline, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return str_replace([self::HL_PRE, self::HL_POST], ['<mark>', '</mark>'], $escaped);
    }
}
