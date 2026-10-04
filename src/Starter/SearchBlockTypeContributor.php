<?php

declare(strict_types=1);

namespace Thallo\Search\Starter;

use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;
use Thallo\Contracts\Style\StyleTargets;

/**
 * The Search block (search block spec §3.1): a search field, or an icon that opens one. Its scope
 * is a plain string whose choices come from the server (`options_source`), because which kinds can
 * be searched depends on which features are on — a fixed list would go stale.
 */
final class SearchBlockTypeContributor implements StarterBlockTypeContributor
{
    public const SLUG = 'search';

    /** @return list<StarterBlockTypeDefinition> */
    public function blockTypeDefinitions(): array
    {
        return [
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-search:' . self::SLUG,
                slug: self::SLUG,
                label: 'Search',
                icon: 'i-lucide-search',
                category: 'Site',
                description: 'A search field, or an icon that opens one.',
                schema: [
                    ['name' => 'display', 'type' => 'enum', 'enum' => ['field', 'icon']],
                    ['name' => 'placeholder', 'type' => 'string'],
                    // Which kind of result; empty for all. The choices come from the available sources.
                    ['name' => 'scope', 'type' => 'string', 'options_source' => 'thallo-search.scopes'],
                    ['name' => 'live_results', 'type' => 'boolean'],
                ],
                requiresCapability: 'thallo.search',
                styleCapabilities: ['spacing', 'width', 'visibility', 'layout.item'],
                styleTargets: StyleTargets::root('box', ['spacing', 'width', 'visibility', 'layout.item']),
            ),
        ];
    }
}
