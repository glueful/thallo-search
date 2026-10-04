<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

use Thallo\Contracts\Search\ResultDisplay;

/** One result as shown: from current records, with a snippet built from current text. */
final class SearchResultItem
{
    public function __construct(
        public readonly string $kind,
        public readonly string $kindLabel,
        public readonly string $sourceId,
        public readonly string $locale,
        public readonly ?string $subtype,
        public readonly ResultDisplay $display,
        public readonly string $snippetHtml,
        public readonly float $score,
    ) {
    }
}
