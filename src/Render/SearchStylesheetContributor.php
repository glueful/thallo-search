<?php

declare(strict_types=1);

namespace Thallo\Search\Render;

use Thallo\Render\Contribution\StylesheetContributor;

/** The Search block's baseline styling, inside the theme artifact like the shop's. */
final class SearchStylesheetContributor implements StylesheetContributor
{
    public function contributorId(): string
    {
        return 'thallo-search.styles';
    }

    public function priority(): int
    {
        return 0;
    }

    public function stylesheets(): array
    {
        return [dirname(__DIR__, 2) . '/assets/search.css'];
    }
}
