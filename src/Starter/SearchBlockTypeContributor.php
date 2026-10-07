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
                // The look is the search field's (the `field` target, its input), in both displays;
                // the block keeps its spacing, width, visibility and placement. Optional: a scope
                // that cannot be searched renders no field.
                styleCapabilities: [
                    'spacing', 'width', 'visibility', 'layout.item',
                    'colors', 'border', 'radius', 'shadow', 'typography',
                ],
                styleTargets: StyleTargets::root('box', ['spacing', 'width', 'visibility', 'layout.item'], [
                    'targets' => ['field' => ['kind' => 'box', 'optional' => true]],
                    'map' => [
                        'colors' => 'field', 'border' => 'field', 'radius' => 'field',
                        'shadow' => 'field', 'typography' => 'field',
                    ],
                ]) + ['parts' => [
                    // The submit button; in the icon display, the icon that opens the field and the
                    // drop-down it opens in. Each a look of its own.
                    'button' => ['label' => 'Button', 'capabilities' => [
                        'colors', 'border', 'radius', 'shadow', 'typography',
                        'spacing.padding.top', 'spacing.padding.right',
                        'spacing.padding.bottom', 'spacing.padding.left',
                    ]],
                    // The magnifier is sized in em, so Size scales it.
                    'icon' => ['label' => 'Icon', 'capabilities' => [
                        'typography.size', 'colors', 'border', 'radius',
                        'spacing.padding.top', 'spacing.padding.right',
                        'spacing.padding.bottom', 'spacing.padding.left',
                    ]],
                    'panel' => ['label' => 'Panel', 'capabilities' => [
                        'colors', 'border', 'radius', 'shadow',
                        'spacing.padding.top', 'spacing.padding.right',
                        'spacing.padding.bottom', 'spacing.padding.left',
                    ]],
                ]],
            ),
        ];
    }
}
