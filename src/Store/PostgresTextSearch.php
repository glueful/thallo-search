<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

/**
 * How the Postgres engine reads a query and a language. A query is WORDS and nothing else: what the
 * visitor typed is cut into words, so no operator reaches the query parser, and each word is bound
 * as a value, never written into SQL.
 */
final class PostgresTextSearch
{
    private const MAX_WORDS = 12;

    /** Language subtag → a text-search configuration Postgres ships; anything else is `simple`. */
    private const CONFIGS = [
        'ar' => 'arabic', 'da' => 'danish', 'nl' => 'dutch', 'en' => 'english', 'fi' => 'finnish',
        'fr' => 'french', 'de' => 'german', 'el' => 'greek', 'hu' => 'hungarian', 'id' => 'indonesian',
        'ga' => 'irish', 'it' => 'italian', 'lt' => 'lithuanian', 'ne' => 'nepali', 'nb' => 'norwegian',
        'nn' => 'norwegian', 'no' => 'norwegian', 'pt' => 'portuguese', 'ro' => 'romanian',
        'ru' => 'russian', 'es' => 'spanish', 'sv' => 'swedish', 'ta' => 'tamil', 'tr' => 'turkish',
    ];

    /** `fr-CA` → `french`; a language Postgres has no configuration for → `simple` (no stemming). */
    public static function configFor(string $locale): string
    {
        $language = strtolower((string) preg_split('/[-_]/', $locale)[0]);
        return self::CONFIGS[$language] ?? 'simple';
    }

    /**
     * What the visitor typed, as words: only letters and digits survive, so nothing typed is ever
     * an operator; repeated words count once; a very long query is cut off.
     *
     * @return list<string>
     */
    public static function words(string $q): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }
}
