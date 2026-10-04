<?php

declare(strict_types=1);

namespace Thallo\Search\Sources;

use Thallo\Contracts\Search\SearchScopeStatus;
use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Search\Query\KindAvailability;

/**
 * For render's `search_scope_state()`: whether a Search block's scope can be searched, and if not,
 * why, phrased for the editor's placeholder ("Products search isn't available: Commerce is off.").
 */
final class RegistrySearchScopeStatus implements SearchScopeStatus
{
    public function __construct(
        private readonly SearchSourceRegistry $sources,
        private readonly KindAvailability $availability,
    ) {
    }

    public function stateOf(string $scope): array
    {
        if (!$this->availability->searchIsOn()) {
            return ['available' => false, 'label' => null, 'reason' => 'Search is off']; // no kind to name
        }
        if ($scope === '') {
            return ['available' => true, 'label' => 'All results', 'reason' => null];
        }
        $contributor = $this->sources->all()[$scope] ?? null;
        if ($contributor === null) {
            return ['available' => false, 'label' => $scope, 'reason' => 'no longer provided by any installed feature'];
        }
        $missing = $this->availability->missingLabel($scope);
        return $missing === null
            ? ['available' => true, 'label' => $contributor->label(), 'reason' => null]
            : ['available' => false, 'label' => $contributor->label(), 'reason' => $missing . ' is off'];
    }
}
