<?php

declare(strict_types=1);

namespace Thallo\Search\Identity;

use Thallo\Contracts\Search\SearchIdentity;

/**
 * A search document's id (search block spec §3.3): `{kind}_{sourceId}_{loc}`, `loc` being `L`
 * plus the locale or `A` for every locale. Kind and source id cannot hold `_`, so splitting on the
 * first two underscores is unambiguous, and every character is valid in a Meilisearch key.
 */
final class DocumentId
{
    public static function encode(string $kind, string $sourceId, string $locale): string
    {
        SearchIdentity::assertKind($kind);
        SearchIdentity::assertSourceId($sourceId);
        SearchIdentity::assertLocale($locale);

        return $kind . '_' . $sourceId . '_' . ($locale === '*' ? 'A' : 'L' . $locale);
    }

    /** @return array{kind: string, sourceId: string, locale: string} */
    public static function decode(string $id): array
    {
        $parts = explode('_', $id, 3);
        if (count($parts) !== 3 || $parts[2] === '' || !in_array($parts[2][0], ['A', 'L'], true)) {
            throw new \InvalidArgumentException("Not a search document id: '{$id}'.");
        }
        $locale = $parts[2] === 'A' ? '*' : substr($parts[2], 1);
        self::encode($parts[0], $parts[1], $locale);

        return ['kind' => $parts[0], 'sourceId' => $parts[1], 'locale' => $locale];
    }
}
