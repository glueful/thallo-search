<?php

declare(strict_types=1);

namespace Thallo\Search\Engine;

/**
 * Which engine answers a search: `postgres`, `meilisearch`, or `unavailable` with the reason.
 *
 * `SEARCH_ENGINE=auto` (the default) is Meilisearch where the site has configured one
 * (MEILISEARCH_HOST is set) and the site's own PostgreSQL otherwise — so a site that ran
 * Meilisearch before this choice existed keeps it, and a new site's search needs nothing
 * installed. A choice that cannot be honoured is never quietly swapped for the other engine:
 * indexing a site into a second engine behind its operator's back is how a search goes stale
 * unnoticed. It is `unavailable`, with the reason, which `search:status` prints.
 */
final class SearchEngineChoice
{
    public const POSTGRES = 'postgres';
    public const MEILISEARCH = 'meilisearch';
    public const UNAVAILABLE = 'unavailable';

    /**
     * @param bool $meilisearchConfigured the site set MEILISEARCH_HOST
     * @param bool $meilisearchEnabled the glueful/meilisearch extension's client is in the container
     * @return array{0: string, 1: ?string} the engine, and why when it is `unavailable`
     */
    public static function resolve(
        string $wanted,
        string $databaseDriver,
        bool $meilisearchConfigured,
        bool $meilisearchEnabled,
    ): array {
        $wanted = strtolower(trim($wanted)) ?: 'auto';
        if (!in_array($wanted, ['auto', self::POSTGRES, self::MEILISEARCH], true)) {
            return [self::UNAVAILABLE, "SEARCH_ENGINE is \"{$wanted}\"; it is auto, postgres or meilisearch."];
        }
        $postgres = $databaseDriver === 'pgsql';

        if ($wanted === self::MEILISEARCH || ($wanted === 'auto' && $meilisearchConfigured)) {
            return $meilisearchEnabled
                ? [self::MEILISEARCH, null]
                : [self::UNAVAILABLE, 'Meilisearch is the search engine, but the glueful/meilisearch extension '
                    . 'is not enabled: php glueful extensions:enable glueful/meilisearch'];
        }
        if ($postgres) {
            return [self::POSTGRES, null];
        }
        return [self::UNAVAILABLE, $wanted === self::POSTGRES
            ? "SEARCH_ENGINE is postgres, but the site's database is {$databaseDriver}: full-text search "
                . 'needs PostgreSQL. Use SEARCH_ENGINE=meilisearch.'
            : "The site's database ({$databaseDriver}) has no full-text search engine: set MEILISEARCH_HOST, "
                . 'or SEARCH_ENGINE=meilisearch.'];
    }
}
