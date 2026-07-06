<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

/** One search hit, engine-neutral. The controller maps this to the public JSON contract. */
final class Hit
{
    public function __construct(
        public readonly string $entryUuid,
        public readonly string $contentTypeSlug,
        public readonly string $locale,
        public readonly string $href,
        public readonly string $title,
        public readonly string $snippet,
        public readonly float $score,
    ) {
    }
}
