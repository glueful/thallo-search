<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

/**
 * The resolved visibility for one request: all-access, or the exact set of content-type
 * uuids (public + scope-granted) this request may see. An empty set without all-access
 * means no results are visible.
 */
final class VisibilityContext
{
    /** @param list<string> $visibleTypeUuids */
    public function __construct(
        public readonly bool $allAccess,
        public readonly array $visibleTypeUuids,
    ) {
    }
}
