<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

/**
 * Meilisearch index names (search block spec §3.3): one per workspace, kind and build attempt, so a
 * workspace's documents are kept apart by structure, and a replacement build never writes into the
 * index its predecessor abandoned. A single-store site has no workspace segment.
 */
final class IndexNameFor
{
    public static function target(string $index, ?string $workspace, string $kind, int $generation): string
    {
        return self::prefix($index, $workspace, $kind) . 'g' . $generation;
    }

    /** Every attempt's index for one workspace and kind starts with this. */
    public static function prefix(string $index, ?string $workspace, string $kind): string
    {
        return $index . '_v2_' . ($workspace !== null && $workspace !== '' ? $workspace . '_' : '') . $kind . '_';
    }
}
