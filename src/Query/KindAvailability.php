<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

use Thallo\Contracts\Search\SearchSourceContributor;
use Thallo\Contracts\Search\SearchSourceRegistry;

/**
 * Which result kinds can be searched now (search block spec §3.2): a registered kind is available
 * when search and every capability it requires are on. Decided from capabilities alone — never from
 * the index's state.
 */
final class KindAvailability
{
    /**
     * @param \Closure(string): bool $isEnabled capability id => enabled
     * @param \Closure(string): string $labelOf capability id => its label
     */
    public function __construct(
        private readonly SearchSourceRegistry $sources,
        private readonly \Closure $isEnabled,
        private readonly \Closure $labelOf,
    ) {
    }

    /** @return array<string, SearchSourceContributor> */
    public function available(): array
    {
        return array_filter(
            $this->sources->all(),
            fn (SearchSourceContributor $c): bool => $this->missing($c) === null,
        );
    }

    public function isAvailable(string $kind): bool
    {
        $contributor = $this->sources->all()[$kind] ?? null;
        return $contributor !== null && $this->missing($contributor) === null;
    }

    /** Why a kind is not available: "Requires Commerce", or that nothing provides it any more. */
    public function reasonFor(string $kind): ?string
    {
        $contributor = $this->sources->all()[$kind] ?? null;
        if ($contributor === null) {
            return 'No longer provided by any installed feature';
        }
        $missing = $this->missing($contributor);
        return $missing === null ? null : 'Requires ' . ($this->labelOf)($missing);
    }

    private function missing(SearchSourceContributor $contributor): ?string
    {
        foreach (['thallo.search', ...$contributor->requiredCapabilities()] as $id) {
            if (!($this->isEnabled)($id)) {
                return $id;
            }
        }
        return null;
    }
}
