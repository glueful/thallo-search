<?php

declare(strict_types=1);

namespace Thallo\Search\Render;

use Thallo\Render\Contribution\ReservedPathContributor;

/**
 * Reserves `/search` and the pack's `/_search/` endpoints whether or not search is on, so a builder
 * page can never sit at them and be exposed again when search is switched off (search block spec
 * §3.7).
 */
final class SearchReservedPathContributor implements ReservedPathContributor
{
    public function contributorId(): string
    {
        return 'thallo-search.paths';
    }

    public function priority(): int
    {
        return 0;
    }

    public function reservedPrefixes(): array
    {
        return ['_search'];
    }

    public function reservedExacts(): array
    {
        return ['search'];
    }
}
