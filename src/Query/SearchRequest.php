<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

/**
 * A validated, visibility-resolved search request. `typeSlug` is null (all accessible types)
 * or an already-validated, accessible slug. `allAccess` + `visibleTypeUuids` come from the
 * VisibilityResolver and are enforced inside the backend filter (never post-filtered).
 */
final class SearchRequest
{
    /** @param list<string> $visibleTypeUuids */
    public function __construct(
        public readonly string $q,
        public readonly string $locale,
        public readonly ?string $typeSlug,
        public readonly bool $allAccess,
        public readonly array $visibleTypeUuids,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }
}
