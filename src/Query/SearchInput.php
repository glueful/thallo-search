<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

use Thallo\Contracts\Search\SearchIdentity;

/**
 * One reading of a search query for every surface (search block spec §3.4). `q` is normalised the
 * same way everywhere: a string only, valid UTF-8, NFC, control characters removed, Unicode
 * whitespace trimmed and collapsed, at most 200 code points. The site's surfaces read `scope`
 * (absent or empty for every kind) and fall back to the default locale; the API reads `kind`
 * (entries by default) and `type`, and refuses what it always refused with a 422.
 */
final class SearchInput
{
    public const MAX_Q = 200;

    private function __construct(
        public readonly string $q,
        public readonly ScopeChoice $scope,
        public readonly string $locale,
        public readonly ?string $type,
        public readonly ?string $rawCursor,
        public readonly ?int $offset,
    ) {
    }

    /**
     * @param array<string, mixed> $query the request's query parameters, as given
     * @param list<string> $locales the site's locales, as configured
     * @param list<string> $registeredKinds every registered result kind
     * @throws InvalidSearchInput on the API surface only
     */
    public static function from(
        array $query,
        Surface $surface,
        array $locales,
        string $defaultLocale,
        array $registeredKinds,
    ): self {
        $q = self::normaliseQ($query['q'] ?? null);
        $api = $surface === Surface::Api;
        if ($api && $q === '') {
            throw new InvalidSearchInput('A non-empty `q` query parameter is required.');
        }

        $type = null;
        if ($api) {
            $scope = self::apiKind($query['kind'] ?? null, $registeredKinds);
            $rawType = $query['type'] ?? null;
            if ($rawType !== null && $rawType !== '') {
                if (!is_string($rawType) || preg_match('/\A[a-z0-9_-]{1,191}\z/', $rawType) !== 1) {
                    throw new InvalidSearchInput('The `type` query parameter is not a content type slug.');
                }
                if ($scope->kind !== 'entries') {
                    throw new InvalidSearchInput('`type` narrows entries; it cannot be combined with that `kind`.');
                }
                $type = $rawType;
            }
        } else {
            $scope = self::siteScope($query['scope'] ?? null, $registeredKinds);
        }

        $locale = self::locale($query['locale'] ?? null, $locales);
        if ($locale === null) {
            if ($api) {
                throw new InvalidSearchInput(
                    ($query['locale'] ?? '') === '' ? 'A `locale` query parameter is required.'
                        : 'The `locale` query parameter is not one of this site\'s locales.',
                );
            }
            $locale = $defaultLocale;
        }

        $cursor = $query['cursor'] ?? null;
        $offset = null;
        if ($api) {
            $rawOffset = $query['offset'] ?? null;
            if ($rawOffset !== null) {
                if ($cursor !== null) {
                    throw new InvalidSearchInput('Use `offset` or `cursor`, not both.');
                }
                if (!is_string($rawOffset) || preg_match('/\A\d{1,9}\z/', $rawOffset) !== 1) {
                    throw new InvalidSearchInput('The `offset` query parameter must be a whole number.');
                }
                $offset = (int) $rawOffset;
            }
        }

        return new self($q, $scope, $locale, $type, is_string($cursor) ? $cursor : null, $offset);
    }

    private static function normaliseQ(mixed $raw): string
    {
        if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
            return '';
        }
        if (class_exists(\Normalizer::class)) {
            $raw = \Normalizer::normalize($raw, \Normalizer::FORM_C) ?: $raw;
        }
        $raw = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $raw);
        $raw = trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $raw));

        return mb_substr($raw, 0, self::MAX_Q);
    }

    /** @param list<string> $kinds */
    private static function siteScope(mixed $raw, array $kinds): ScopeChoice
    {
        if ($raw === null || $raw === '') {
            return ScopeChoice::allKinds();
        }
        if (!is_string($raw)) {
            return ScopeChoice::unavailable('');
        }
        $kind = strtolower($raw);
        if (preg_match(SearchIdentity::KIND, $kind) !== 1 || !in_array($kind, $kinds, true)) {
            return ScopeChoice::unavailable(mb_substr($kind, 0, 32));
        }
        return ScopeChoice::kind($kind);
    }

    /** @param list<string> $kinds */
    private static function apiKind(mixed $raw, array $kinds): ScopeChoice
    {
        if ($raw === null || $raw === '') {
            return ScopeChoice::kind('entries');
        }
        if ($raw === 'all') {
            return ScopeChoice::allKinds();
        }
        if (is_string($raw) && in_array($raw, $kinds, true)) {
            return ScopeChoice::kind($raw);
        }
        throw new InvalidSearchInput('The `kind` query parameter names no search result kind.');
    }

    /** @param list<string> $locales */
    private static function locale(mixed $raw, array $locales): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        foreach ($locales as $locale) {
            if (strcasecmp($locale, $raw) === 0) {
                return $locale;
            }
        }
        return null;
    }
}
