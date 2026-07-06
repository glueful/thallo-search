# lemma-search

Public, delivery-parity **content search** for [Lemma](https://getlemma.dev), backed by
[Meilisearch](https://www.meilisearch.com/) — shipped as a removable capability pack.

lemma-search owns Lemma semantics (published-only visibility, `href`/`title`, lifecycle sync,
the `ContentReindexer` seam); the `glueful/meilisearch` extension owns the search mechanics. A
single class (`LiveMeilisearchIndex`) touches Meilisearch, behind a pack-owned `SearchBackend`
port — so a Postgres FTS backend could plug in later without touching anything else.

## Install & enable

Unlike Lemma's infra-free packs (seo, analytics, collections, importers), lemma-search is
**opt-in, not bundled-on by default** — it needs a running Meilisearch, so a lean install ships it
**off** (no search reindexer is bound, `/v1/search` is not registered). It is still fully
discoverable: `php glueful extensions:list` shows it under **Available (off)** (`○`), and
`php glueful extensions:info lemma-search` shows its details.

To add it to an existing app (it lives as a path package in this monorepo):

1. `composer require glueful/lemma-search`
2. Ensure Meilisearch is reachable (configure the `glueful/meilisearch` extension).
3. `php glueful extensions:enable lemma-search` — writes the provider into the
   `config/extensions.php` allow-list and recompiles the extension cache (or add the FQCN
   `Glueful\Lemma\Search\LemmaSearchServiceProvider` to that list by hand).
4. `php glueful search:reindex` — backfill the index from published content.

The pack registers **no migrations** (Meilisearch owns storage). Once enabled, the `lemma.search`
capability is on; disable it without removing the extension by setting `'lemma.search' => false` in
`config/lemma.php`'s `capabilities` switchboard (routes then `404` and the reindexer resolves to a
no-op). When Meilisearch is missing or unhealthy, the endpoint fails closed (503) and live
reindexing no-ops without ever breaking a publish.

## Endpoint

```
GET /v1/search?q=<terms>&locale=<code>[&type=<slug>][&limit=<n>][&offset=<n>]
```

Behind `optional_api_key`: an authenticated key narrows visibility to its scopes; an anonymous
request sees only content types with `public_delivery = true`. Visibility is enforced **inside**
the Meilisearch filter, so `total` and pagination stay correct.

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

## Configuration (`config/lemma-search.php`)

| Key | Default | Meaning |
| --- | --- | --- |
| `index` | `lemma_content` | Meilisearch index name (one shared content index). |
| `snippet_length` | `40` | Highlighted-body crop length, in words. |
| `default_limit` | `20` | Page size when `limit` is omitted. |
| `max_limit` | `50` | Upper bound for `limit`. |
| `types.<slug>` | — | Optional per-type field selection (see below). |

By default every **string/text** schema field is indexed; the title is the `title` field, else
the entry label, else the first indexed string field. Override per content type:

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

Publish/unpublish/update/delete events flow through Lemma's existing `ContentReindexer` seam
(identity-only). A per-locale event re-reads and upserts (or deletes that locale's doc); a
whole-entry delete (`locale = null`) purges every locale doc. Reindexing runs in the pipeline's
after-commit and is wrapped so a search-backend failure is logged, never breaking the publish —
`search:reindex` recovers.

## v1 scope

Content search only. **Not** in v1: collections-row search, an admin search UI, a Postgres FTS
backend, and any search-permission migration.
