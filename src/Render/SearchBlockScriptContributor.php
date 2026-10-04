<?php

declare(strict_types=1);

namespace Thallo\Search\Render;

use Thallo\Render\Contribution\BlockScriptContributor;
use Thallo\Search\Assets\SearchAssetMap;

/** The Search block's script, at its fingerprinted URL, for `block_script('search')`. */
final class SearchBlockScriptContributor implements BlockScriptContributor
{
    public function __construct(private readonly SearchAssetMap $assets)
    {
    }

    public function contributorId(): string
    {
        return 'thallo-search.scripts';
    }

    public function priority(): int
    {
        return 0;
    }

    public function blockScripts(): array
    {
        $file = $this->assets->fingerprintedName('search.js') ?? 'search.js';
        return ['search' => '/_thallo/search/' . rawurlencode($file)];
    }
}
