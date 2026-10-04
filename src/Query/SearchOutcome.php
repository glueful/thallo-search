<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

/**
 * What a search answered (search block spec §3.7). `state` is one of: `results`; `empty_batch` (none
 * shown here, but more remain — follow `next`); `no_matches` (the engine has no more); `no_query`;
 * `scope_unavailable`; `rebuilding`; `unavailable`. `total` is the engine's count and is always
 * approximate: candidates it counted may be dropped when presented.
 */
final class SearchOutcome
{
    public const RESULTS = 'results';
    public const EMPTY_BATCH = 'empty_batch';
    public const NO_MATCHES = 'no_matches';
    public const NO_QUERY = 'no_query';
    public const SCOPE_UNAVAILABLE = 'scope_unavailable';
    public const REBUILDING = 'rebuilding';
    public const UNAVAILABLE = 'unavailable';

    public readonly bool $totalApproximate;

    /** @param list<SearchResultItem> $items */
    public function __construct(
        public readonly string $state,
        public readonly array $items = [],
        public readonly ?string $next = null,
        public readonly int $total = 0,
        public readonly ?string $unavailableReason = null,
        public readonly ?string $scopeLabel = null,
    ) {
        $this->totalApproximate = true;
    }

    public static function of(string $state, ?string $reason = null, ?string $label = null): self
    {
        return new self($state, [], null, 0, $reason, $label);
    }
}
