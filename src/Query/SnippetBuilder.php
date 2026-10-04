<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

/**
 * A result's snippet, from the contributor's current text (search block spec §3.7). Spans are found
 * by best-effort literal matching in the plain text — case-insensitive, Unicode-aware, at word
 * starts — and the text is escaped segment by segment with trusted `<mark>` tags between, so
 * nothing in the text can become markup and an entity can never be matched. A stemmed match with
 * no literal occurrence gives the opening excerpt, unhighlighted.
 */
final class SnippetBuilder
{
    public static function build(string $text, string $q, int $maxChars = 160): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $spans = [];
        if ($words !== [] && $text !== '') {
            $pattern = '/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(
                static fn (string $w): string => preg_quote($w, '/'),
                $words,
            )) . ')[\p{L}\p{N}]*/iu';
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [$match, $byte]) {
                    $spans[] = [mb_strlen(substr($text, 0, $byte)), mb_strlen($match)];
                }
            }
        }

        // The window: around the first match, or the opening.
        $length = mb_strlen($text);
        $start = 0;
        if ($spans !== [] && $length > $maxChars) {
            $start = max(0, $spans[0][0] - intdiv($maxChars, 3));
        }
        $end = min($length, $start + $maxChars);

        $out = $start > 0 ? '…' : '';
        $cursor = $start;
        foreach ($spans as [$at, $len]) {
            if ($at < $cursor || $at + $len > $end) {
                continue;
            }
            $out .= self::escape(mb_substr($text, $cursor, $at - $cursor));
            $out .= '<mark>' . self::escape(mb_substr($text, $at, $len)) . '</mark>';
            $cursor = $at + $len;
        }
        $out .= self::escape(mb_substr($text, $cursor, $end - $cursor));
        return $out . ($end < $length ? '…' : '');
    }

    private static function escape(string $segment): string
    {
        return htmlspecialchars($segment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
