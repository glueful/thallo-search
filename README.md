# thallo-search

Public, delivery-parity **content search** for [Thallo](https://thallo.dev) — shipped as a
removable capability pack, with two engines behind one port:

- **PostgreSQL full-text search** — the database your site already has. Nothing to install,
  nothing to run. Stemming in the page's language, prefix matching for a search-as-you-type
  box, ranked with titles above bodies, highlighted snippets.
- **[Meilisearch](https://www.meilisearch.com/)** — for a site that wants typo tolerance and
  runs the server; the `glueful/meilisearch` extension owns the mechanics.

thallo-search owns Thallo semantics (published-only visibility, `href`/`title`, lifecycle sync,
the `ContentReindexer` seam). Everything but the engines depends only on the `SearchBackend`
port.

## Turn it on

Search ships **off**. Turn it on in the admin under **Settings › General › Content search**, or
set `'thallo.search' => true` in `config/thallo.php`'s `capabilities`. Then index what is already
published:

```bash
php glueful search:reindex
php glueful search:status     # which engine answers, and whether it can
```

From then on publishing, updating, unpublishing and deleting keep the index in step. While the
capability is off, `/v1/search` is not registered (404) and the reindexer is a no-op.

## Which engine

`SEARCH_ENGINE` is `auto` (the default), `postgres` or `meilisearch`.

| `SEARCH_ENGINE` | Engine |
| --- | --- |
| `auto` | Meilisearch if `MEILISEARCH_HOST` is set, otherwise PostgreSQL. |
| `postgres` | PostgreSQL full-text search. Needs the site's database to be PostgreSQL. |
| `meilisearch` | Meilisearch. Needs the `glueful/meilisearch` extension enabled and a reachable server. |

A choice that cannot be honoured is never quietly swapped for the other engine — indexing a site
into a second engine behind its operator's back is how a search goes stale unnoticed. Search is
then **unavailable**: the endpoint answers 503, publishing is never affected, and
`search:status` says why. After changing engines, run `search:reindex`.

The PostgreSQL engine keeps its index in the `search_documents` table (the pack's one migration;
`php glueful thallo:provision` creates it). Postgres maintains the search vector itself, as a
generated column: each text in the page's language, where words meet by their stem, and as
written, where a typed prefix can match. A locale maps to a text-search configuration by its
language (`fr-CA` → `french`); a language Postgres has none for uses `simple`, which matches
whole words and prefixes without stemming. With workspaces on, the table is workspace-owned like
every other content table.

**A query is words and nothing else.** What a visitor types is cut into words; each must match,
by its stem or as a prefix, so more words narrow a search. No operator a visitor types reaches
the query parser. A single letter is a word, not the start of every word beginning with it.

## Endpoint

```
GET /v1/search?q=<terms>&locale=<code>[&type=<slug>][&limit=<n>][&offset=<n>]
```

Behind `optional_api_key`: an authenticated key narrows visibility to its scopes; an anonymous
request sees only content types with `public_delivery = true`. Visibility is enforced **inside**
the engine's query, so `total` and pagination stay correct.

Response — the payload is wrapped in the framework's standard `data` envelope:

```json
{
  "success": true,
  "data": {
    "hits": [
      {
        "uuid": "e-1",
        "type": "blog",
        "locale": "en",
        "href": "/en/blog/climate",
        "title": "The climate crisis",
        "snippet": "…the <mark>climate</mark> crisis…",
        "score": 0.98
      }
    ],
    "total": 42,
    "limit": 20,
    "offset": 0
  }
}
```

- **Highlighting:** the only markup in `snippet` is `<mark>…</mark>`; all other source markup is
  HTML-escaped, so the snippet is safe to render without client-side sanitising. `title` is plain
  text (no highlighting).
- **Visibility & `type` (delivery parity, matches `DeliveryAccessMiddleware`):**
  - `read:content` scope ⇒ all types; `read:content:{slug}` scopes ⇒ those types; anonymous ⇒
    `public_delivery` types only.
  - `type` **omitted** → results span every accessible type; inaccessible types are silently
    excluded.
  - `type` provided but **inaccessible** → **403**. Unknown `type` → **404**. Accessible `type`
    → results filtered to it.
- **Status codes:** empty `q` → 422; missing `locale` → 422; unknown `type` → 404; inaccessible
  `type` → 403; backend unhealthy → 503. `limit` is clamped to `[1, max_limit]`; `offset` ≥ 0.

## Configuration (`config/search.php`)

| Key | Default | Meaning |
| --- | --- | --- |
| `engine` | `auto` | `SEARCH_ENGINE`: `auto`, `postgres` or `meilisearch` (above). |
| `index` | `content` | Meilisearch index name (one shared content index). |
| `snippet_length` | `40` | Highlighted-body crop length, in words. |
| `default_limit` | `20` | Page size when `limit` is omitted. |
| `max_limit` | `50` | Upper bound for `limit`. |
| `types.<slug>` | — | Optional per-type field selection (see below). |

By default every **string/text** schema field is indexed; the title is the `title` field, else
the entry label, else the first indexed string field. A body is indexed as the words a reader
sees: rich text loses its tags, Markdown in a plain text field (a docs page) loses its syntax,
and a field whose whole value is a URL or a file path is not prose and is left out. Override per
content type:

```php
'types' => [
    'blog' => [
        'title_field'    => 'headline',
        'body_fields'    => ['summary', 'body'],
        'exclude_fields' => ['seo_description'],
        'weights'        => ['headline' => 5, 'summary' => 2, 'body' => 1],
    ],
],
```

`weights` order the fields concatenated into the searchable `body` (higher weight first).
Unknown or non-string configured fields are skipped at runtime and reported by `search:status`.

## Commands

```bash
php glueful search:reindex [--type=<slug>] [--locale=<code>]   # backfill the index from published content
php glueful search:status                                       # doctor: backend health + config warnings
```

**Real-server smoke test:** the unit suite fakes the Meilisearch seam, so Meilisearch's
actual contract (document-id charset, filterable attributes, delete-by-filter) is only
exercised by `tests/Integration/Search/MeilisearchSmokeTest.php` — run it against a live
server with `MEILISEARCH_SMOKE=1 vendor/bin/phpunit --filter MeilisearchSmokeTest` before
shipping index-shape changes.

**No visibility drift:** visibility is resolved from the live content-type store on every
request (nothing visibility-related is denormalized into documents), so flipping a content
type's `public_delivery` flag takes effect in search immediately — no reindex needed.

## Lifecycle

Publish/unpublish/update/delete events flow through Thallo's existing `ContentReindexer` seam
(identity-only). A per-locale event re-reads and upserts (or deletes that locale's doc); a
whole-entry delete (`locale = null`) purges every locale doc. Reindexing runs in the pipeline's
after-commit and is wrapped so a search-backend failure is logged, never breaking the publish —
`search:reindex` recovers.

## Scope

Content search only. **Not** here: collections-row search, an admin search UI, typo tolerance
on the PostgreSQL engine, and any search-permission migration.

## Contributing

This repository is a read-only mirror, published from
[glueful/thallo](https://github.com/glueful/thallo) on every release; its `main` is overwritten
by the next split, so nothing can land here. Issues and pull requests belong in glueful/thallo,
where this code lives at `packages/thallo-search/`.
