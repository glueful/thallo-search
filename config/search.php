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

    // Snippet crop length, in words, for highlighted body excerpts.
    'snippet_length' => (int) env('SEARCH_SNIPPET_LENGTH', 40),

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
