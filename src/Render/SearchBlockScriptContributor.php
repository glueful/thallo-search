<?php

declare(strict_types=1);

namespace Thallo\Search\Render;

use Thallo\Render\Contribution\BlockScriptContributor;
use Thallo\Search\Assets\SearchAssetMap;

/**
 * The Search block's script, at its fingerprinted URL, for `block_script('search')`. The asset map
 * is read when the contributions freeze — on the first render — never on a boot that renders nothing.
 */
final class SearchBlockScriptContributor implements BlockScriptContributor
{
    /** @param \Closure(): SearchAssetMap $assets */
    public function __construct(private readonly \Closure $assets)
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
        $file = ($this->assets)()->fingerprintedName('search.js') ?? 'search.js';
        return ['search' => '/_thallo/search/' . rawurlencode($file)];
    }
}
