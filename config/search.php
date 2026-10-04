<?php

declare(strict_types=1);

return [
    // The enable/disable switch is Extensions › Capabilities (or Settings › General); the host
    // `thallo.capabilities` map (thallo.search) is only the default until it is flipped there.

    // Which engine answers: auto | postgres | meilisearch. `auto` is Meilisearch where the site
    // has configured one (MEILISEARCH_HOST is set) and the site's own PostgreSQL otherwise — so
    // search needs nothing installed. Changing engines: run `php glueful search:reindex`.
    'engine' => env('SEARCH_ENGINE', 'auto'),
    'meilisearch_configured' => env('MEILISEARCH_HOST') !== null && env('MEILISEARCH_HOST') !== '',

    // Meilisearch index name (the pack owns ONE shared content index).
    'index' => env('SEARCH_INDEX', 'content'),

    // The index lifecycle (search block spec §3.5). Seconds a build's or a drainer's lease lasts;
    // how long one engine request may take and the margin kept before a lease ends (a drainer stops
    // sending with less left, and a takeover waits that long past the old lease); and how long a
    // Meilisearch task is awaited before it is reported as still pending.
    'build_lease' => (int) env('SEARCH_BUILD_LEASE', 120),
    'drainer_lease' => (int) env('SEARCH_DRAINER_LEASE', 60),
    'request_timeout' => (int) env('SEARCH_REQUEST_TIMEOUT', 10),
    'lease_margin' => (int) env('SEARCH_LEASE_MARGIN', 5),
    'meilisearch_task_timeout' => (int) env('SEARCH_MEILISEARCH_TASK_TIMEOUT', 10),
    // Documents per rebuild batch, and how long a retired Meilisearch index is kept before it is
    // deleted — longer than any query may take, so a query that read its name just before a
    // promotion still finds it.
    'build_batch' => (int) env('SEARCH_BUILD_BATCH', 200),
    'retire_grace' => (int) env('SEARCH_RETIRE_GRACE', 120),
    // Seconds a rebuild request may wait unclaimed before Settings › Search says background
    // processing hasn't picked it up.
    'stall_after' => (int) env('SEARCH_STALL_AFTER', 600),

    // Snippet crop length, in words, for highlighted body excerpts.
    'snippet_length' => (int) env('SEARCH_SNIPPET_LENGTH', 40),

    // The /search page: results per page, and searches allowed per client per minute.
    'page_size' => (int) env('SEARCH_PAGE_SIZE', 10),
    'page_rate_limit' => (int) env('SEARCH_PAGE_RATE_LIMIT', 60),

    // Query pagination bounds.
    'default_limit' => 20,
    'max_limit' => 50,

    // Optional per-type field selection override (keyed by content-type slug). When absent
    // for a type, the builder indexes every string/text schema field with a convention title.
    //   'blog' => [
    //     'title_field'    => 'headline',
    //     'body_fields'    => ['summary', 'body'],
    //     'exclude_fields' => ['seo_description'],
    //     'weights'        => ['headline' => 5, 'summary' => 2, 'body' => 1],
    //   ],
    'types' => [],
];
