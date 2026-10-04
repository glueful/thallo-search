<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\SearchAudience;
use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Search\Engine\IndexNotFound;
use Thallo\Search\Lifecycle\SearchIndexLocator;
use Thallo\Search\Lifecycle\Workspace;
use Thallo\Search\Store\IndexStore;
use Thallo\Search\Store\StoreQuery;
use Thallo\Search\Store\Target;

/**
 * The one query path behind `/v1/search`, the suggestions and the results page (search block spec
 * §3.4). The engine matches and ranks, filtered by each kind's visibility; each contributor then
 * decides, from current records, what is shown — so a withdrawn item, or text removed since it was
 * indexed, is never displayed. Dropped candidates are refilled from further batches (at most three
 * more); the cursor advances past examined candidates only. An unavailable scope never widens, and
 * a batch with nothing to show while more remain is not "no results".
 */
final class SearchQueryService
{
    private const EXTRA_BATCHES = 3;

    public function __construct(
        private readonly SearchSourceRegistry $sources,
        private readonly KindAvailability $availability,
        private readonly IndexStore $store,
        private readonly SearchIndexLocator $locator,
        private readonly CursorSigner $cursors,
        private readonly Workspace $workspace,
    ) {
    }

    public function search(
        SearchInput $input,
        SearchAudience $audience,
        int $limit,
        bool $refill,
        ?string $typeUuid = null,
    ): SearchOutcome {
        if ($input->q === '') {
            return SearchOutcome::of(SearchOutcome::NO_QUERY);
        }
        if ($input->scope->isUnavailable()) {
            return SearchOutcome::of(SearchOutcome::SCOPE_UNAVAILABLE, 'No longer provided by any installed feature');
        }
        $kinds = $input->scope->isAll() ? array_keys($this->availability->available()) : [(string) $input->scope->kind];
        if (!$input->scope->isAll() && !$this->availability->isAvailable($kinds[0])) {
            $contributor = $this->sources->all()[$kinds[0]] ?? null;
            return SearchOutcome::of(
                SearchOutcome::SCOPE_UNAVAILABLE,
                $this->availability->reasonFor($kinds[0]),
                $contributor?->label(),
            );
        }

        // Every kind that has been built is searched; only when none of them has is the answer
        // "rebuilding" — a kind still on its first build drops out of an all-kinds search.
        $filters = [];
        foreach ($kinds as $kind) {
            if ($this->locator->readMode($kind) !== 'ready') {
                continue;
            }
            $filter = $this->sources->all()[$kind]->visibilityFilter($audience);
            if ($kind === 'entries' && $typeUuid !== null) {
                $filter = self::narrow($filter, $typeUuid);
            }
            $filters[$kind] = $filter;
        }
        if ($filters === []) {
            return SearchOutcome::of(SearchOutcome::REBUILDING);
        }

        $binding = CursorBinding::of($input, $this->workspace->current() ?? '', $audience);
        $start = 0;
        if ($input->rawCursor !== null) {
            $cursor = $this->cursors->verify($input->rawCursor, $binding);
            $start = $cursor?->rawOffset ?? 0;
        } elseif ($input->offset !== null) {
            $start = $input->offset;
        }

        try {
            return $this->collect($input, $audience, $limit, $refill, $filters, $start, $binding);
        } catch (\Throwable) {
            return SearchOutcome::of(SearchOutcome::UNAVAILABLE);
        }
    }

    /** @param array<string, KindFilter> $filters */
    private function collect(
        SearchInput $input,
        SearchAudience $audience,
        int $limit,
        bool $refill,
        array $filters,
        int $start,
        string $binding,
    ): SearchOutcome {
        $items = [];
        $examined = 0;
        $total = 0;
        $exhausted = false;
        $batches = 1 + ($refill ? self::EXTRA_BATCHES : 0);
        for ($batch = 0; $batch < $batches; $batch++) {
            $result = $this->query(new StoreQuery(
                $input->q,
                $input->locale,
                $filters,
                $limit,
                $start + $examined,
            ));
            $total = $result->total;
            if ($result->hits === []) {
                $exhausted = true;
                break;
            }
            $presented = $this->present($audience, $input->locale, $result->hits);
            foreach ($result->hits as $hit) {
                $examined++;
                $display = $presented[$hit->kind][$hit->sourceId] ?? null;
                if ($display !== null) {
                    $items[] = new SearchResultItem(
                        $hit->kind,
                        $this->sources->all()[$hit->kind]->label(),
                        $hit->sourceId,
                        $hit->locale,
                        $hit->subtype,
                        $display,
                        SnippetBuilder::build($display->text, $input->q),
                        $hit->score,
                    );
                    if (count($items) === $limit) {
                        break 2;
                    }
                }
            }
            if ($start + $examined >= $total) {
                $exhausted = true;
                break;
            }
        }
        if (!$exhausted && $start + $examined >= $total) {
            $exhausted = true;
        }
        $next = $exhausted ? null : $this->cursors->sign(new Cursor($binding, $start + $examined));
        $none = $exhausted ? SearchOutcome::NO_MATCHES : SearchOutcome::EMPTY_BATCH;
        $state = $items !== [] ? SearchOutcome::RESULTS : $none;
        return new SearchOutcome($state, $items, $next, $total);
    }

    /** One engine query; a missing index reloads the active targets and is retried once. */
    private function query(StoreQuery $query): \Thallo\Search\Store\StoreResult
    {
        try {
            return $this->store->search($this->targets(array_keys($query->kinds)), $query);
        } catch (IndexNotFound) {
            return $this->store->search($this->targets(array_keys($query->kinds)), $query);
        }
    }

    /**
     * @param list<string> $kinds
     * @return array<string, Target>
     */
    private function targets(array $kinds): array
    {
        $targets = [];
        foreach ($kinds as $kind) {
            $active = $this->locator->targets($kind)['active'];
            if ($active !== null) {
                $targets[$kind] = $active;
            }
        }
        return $targets;
    }

    /**
     * @param list<\Thallo\Search\Store\StoreHit> $hits
     * @return array<string, array<string, ?\Thallo\Contracts\Search\ResultDisplay>>
     */
    private function present(SearchAudience $audience, string $locale, array $hits): array
    {
        $byKind = [];
        foreach ($hits as $hit) {
            $byKind[$hit->kind][] = $hit->sourceId;
        }
        $presented = [];
        foreach ($byKind as $kind => $ids) {
            $contributor = $this->sources->all()[$kind] ?? null;
            $presented[$kind] = $contributor === null ? [] : $contributor->present(
                $audience,
                $locale,
                array_values(array_unique($ids)),
            );
        }
        return $presented;
    }

    private static function narrow(KindFilter $filter, string $typeUuid): KindFilter
    {
        return match ($filter->mode) {
            KindFilter::ALL => KindFilter::subtypes([$typeUuid]),
            KindFilter::SUBTYPES => KindFilter::subtypes(in_array(
                $typeUuid,
                $filter->subtypes,
                true,
            ) ? [$typeUuid] : []),
            default => KindFilter::none(),
        };
    }
}
