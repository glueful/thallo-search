<?php

declare(strict_types=1);

namespace Thallo\Search\Sources;

use Thallo\Contracts\Fields\FieldOptionSource;
use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Search\Query\KindAvailability;

/**
 * The Search block's scope choices (search block spec §3.9): "All results", then every registered
 * kind with whether it can be searched now and, if not, why. Kinds no contributor provides any more
 * are absent — which is how the editor knows a stored one is no longer provided.
 */
final class SearchScopesOptionSource implements FieldOptionSource
{
    public const ID = 'thallo-search.scopes';

    public function __construct(
        private readonly SearchSourceRegistry $sources,
        private readonly KindAvailability $availability,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function permission(): string
    {
        return 'content.edit';
    }

    public function options(): array
    {
        $options = [['value' => '', 'label' => 'All results', 'available' => true, 'reason' => null]];
        foreach ($this->sources->all() as $kind => $contributor) {
            $available = $this->availability->isAvailable($kind);
            $options[] = [
                'value' => $kind,
                'label' => $contributor->label(),
                'available' => $available,
                'reason' => $available ? null : $this->availability->reasonFor($kind),
            ];
        }
        return $options;
    }
}
