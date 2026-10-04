# thallo-search

Public, delivery-parity **search** for [Thallo](https://thallo.dev) — shipped as a capability
pack: a **Search block** (a field, or an icon that opens one, header-ready), a `/search` results
page, `GET /v1/search`, and **Settings › Search** in the admin. Packs contribute kinds of result
(entries ship here; Commerce adds products) through `SearchSourceContributor`. Two engines:

- **PostgreSQL full-text search** — the database your site already has. Nothing to install,
  nothing to run. Stemming in the page's language, prefix matching for a search-as-you-type
  box, ranked with titles above bodies, highlighted snippets.
- **[Meilisearch](https://www.meilisearch.com/)** — for a site that wants typo tolerance and
  runs the server; the `glueful/meilisearch` extension owns the mechanics.

thallo-search owns the index: identity, workspace isolation, the build lifecycle, and one query
path every surface shares. What a result shows is always read from current records by its
contributor's `present()`, never from the index. The contracts are documented in the
[Thallo docs](https://thallo.dev/docs) under Reference › Search sources.

## Turn it on

Search ships **off** (core's `thallo.capabilities` config map sets `'thallo.search' => false`).
Turn it on in the admin under **Settings › General › Content search** or **Extensions › Capabilities**; both write the same system-wide switch, which overrides the config default.
Switching it on requests a build, which the scheduler picks up within a minute; from then on
every save reaches the index on its own. Watch it in **Settings › Search**, or:

```bash
php glueful search:status     # the engine, and each kind's index
```

Indexing needs the queue worker and the scheduler running. While the capability is off,
`/v1/search` is not registered (404), `/search` is the theme's 404, and changes are not recorded.

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
`search:status` says why. After a switch of engines every kind rebuilds itself on the new one.

The PostgreSQL engine keeps its index in the `search_documents` table (`php glueful
thallo:provision` creates it). Postgres maintains the search vector itself, as a
generated column: each text in the page's language, where words meet by their stem, and as
written, where a typed prefix can match. A locale maps to a text-search configuration by its
language (`fr-CA` → `french`); a language Postgres has none for uses `simple`, which matches
whole words and prefixes without stemming. With workspaces on, the table is workspace-owned like
every other content table.

**A query is words and nothing else.** What a visitor types is cut into words; each must match,
by its stem or as a prefix, so more words narrow a search. No operator a visitor types reaches
the query parser. A single letter is a word, not the start of every word beginning with it.

Meilisearch needs **server 1.10 or newer**. Each workspace and kind gets its own index, one per
build attempt (`{SEARCH_INDEX}_v2_[{workspace}_]{kind}_g{n}`); a replaced index is deleted after
`retire_grace`.

## Endpoint

```
GET /v1/search?q=<terms>&locale=<code>[&kind=<kind>][&type=<slug>][&limit=<n>][&offset=<n>|&cursor=<c>]
```

`kind` omitted is entries only (the endpoint's original behaviour); `all` searches every
available kind; `products` (with Commerce) searches products. `type` narrows entries and cannot
be combined with another `kind`. `cursor` is the opt-in continuation: pass the previous
response's `next`; a cursor page is refilled when results are dropped as not visible, while
`offset` keeps fixed windows. A cursor is signed and bound to the query, the caller and the
workspace.

Behind `optional_api_key`: an authenticated key narrows visibility to its scopes; an anonymous
request sees only content types with `public_delivery = true`. Visibility is enforced **inside**
the engine's query, so `total` and pagination stay correct.

Response — the payload is wrapped in the framework's standard `success`/`message`/`data`
envelope:

```json
{
  "success": true,
  "message": "Success",
  "data": {
    "hits": [
      {
        "kind": "entries",
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
    "total_approximate": false,
    "limit": 20,
    "offset": 0,
    "next": null
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
- **Other kinds:** a non-entry hit carries `source_id` instead of `uuid`/`type`, plus `image` and
  `price` when its contributor provides them. `total_approximate` is true when `total` is an
  estimate.
- **Status codes:** empty `q`, missing or unknown `locale`, unknown `kind`, `offset` with `cursor`,
  `type` with a non-entries `kind`, or an unavailable kind → 422; a `cursor` not valid for the
  query → 400; unknown `type` → 404; inaccessible `type` → 403; engine unavailable or an index
  still being built for the first time → 503. `limit` is clamped to `[1, max_limit]`; `offset` ≥ 0.

## Configuration (`config/search.php`)

| Key | Default | Meaning |
| --- | --- | --- |
| `engine` | `auto` | `SEARCH_ENGINE`: `auto`, `postgres` or `meilisearch` (above). |
| `meilisearch_configured` | from env | True when `MEILISEARCH_HOST` is set and non-empty; drives `auto`. |
| `index` | `content` | `SEARCH_INDEX`: the prefix of every Meilisearch index name. |
| `snippet_length` | `40` | `SEARCH_SNIPPET_LENGTH`: highlighted-body crop length, in words. |
| `page_size` | `10` | `SEARCH_PAGE_SIZE`: results per `/search` page. |
| `page_rate_limit` | `60` | `SEARCH_PAGE_RATE_LIMIT`: `/search` searches per client per minute. |
| `build_lease` | `120` | `SEARCH_BUILD_LEASE`: seconds a build's claim lasts before renewal. |
| `drainer_lease` | `60` | `SEARCH_DRAINER_LEASE`: the same, for applying live changes. |
| `request_timeout` | `10` | `SEARCH_REQUEST_TIMEOUT`: seconds one engine request may take. |
| `lease_margin` | `5` | `SEARCH_LEASE_MARGIN`: seconds before a claim ends in which nothing more is sent. |
| `meilisearch_task_timeout` | `10` | `SEARCH_MEILISEARCH_TASK_TIMEOUT`: seconds a Meilisearch task is awaited. |
| `build_batch` | `200` | `SEARCH_BUILD_BATCH`: documents per rebuild batch. |
| `retire_grace` | `120` | `SEARCH_RETIRE_GRACE`: seconds a replaced Meilisearch index is kept. |
| `stall_after` | `600` | `SEARCH_STALL_AFTER`: seconds a request may wait unclaimed before Settings › Search flags it. |
| `default_limit` | `20` | Page size when `limit` is omitted. |
| `max_limit` | `50` | Upper bound for `limit`. |
| `types.<slug>` | — | Optional per-type field selection (see below). |

By default every **string/text** schema field is indexed; the title is the `title` field, else
the entry's slug, else the first indexed string field (this fallback applies only when no
`title_field` is configured). A body is indexed as the words a reader
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
php glueful search:reindex [--kind=<kind>] [--wait]   # request a rebuild; --wait runs it now
php glueful search:reconcile [--full]                 # the scheduled worker (every minute; --full daily)
php glueful search:status [--all]                     # engine readiness and each kind's index
```

`search:reindex --type`/`--locale` are removed: they exit non-zero without touching the index.

**Real-server smoke test:** the unit suite fakes the Meilisearch seam, so Meilisearch's
actual contract (document-id charset, filterable attributes, delete-by-filter) is only
exercised by `tests/Integration/Search/MeilisearchSmokeTest.php` — run it against a live
server with `MEILISEARCH_SMOKE=1 vendor/bin/phpunit --filter MeilisearchSmokeTest` before
shipping index-shape changes.

**No visibility drift:** visibility is resolved from the live content-type store on every
request (nothing visibility-related is denormalized into documents), so flipping a content
type's `public_delivery` flag takes effect in search immediately — no reindex needed.

## Lifecycle

Each workspace and kind has a state row. A change (an entry's publish, `SearchIndex::changed()`
from a pack) is appended to a journal under that row's lock after the source's own transaction
commits, and a queued wake-up drains it to every live target, acknowledging per target. A rebuild
claims the kind with a lease and a fresh generation, writes a new target from `enumerate()`, and
is promoted only once every journal entry is acknowledged on it; fences keep a builder, a drainer
and a superseded attempt from writing over each other. Rebuild requests are durable: the
scheduled `search:reconcile` picks them up every minute, and `--full` once a day rebuilds every
kind, repairing any change lost between a commit and its event. A failure is recorded on the row
(**Settings › Search** shows it) and never breaks the save. A kind answers "rebuilding" until
its first build is promoted; an all-kinds search leaves such a kind out.

## Scope

Public search of published content and pack-contributed kinds. **Not** here: collections-row
search, searching the admin's own lists, typo tolerance on the PostgreSQL engine, and any
search-permission migration.

## Contributing

This repository is a read-only mirror, published from
[glueful/thallo](https://github.com/glueful/thallo) on every release; its `main` is overwritten
by the next split, so nothing can land here. Issues and pull requests belong in glueful/thallo,
where this code lives at `packages/thallo-search/`.
