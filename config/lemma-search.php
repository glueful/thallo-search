<?php

declare(strict_types=1);

return [
    // The real enable/disable switch is the host `lemma.capabilities` map (lemma.search).
    // Meilisearch index name (the pack owns ONE shared content index).
    'index' => env('SEARCH_INDEX', 'lemma_content'),

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
