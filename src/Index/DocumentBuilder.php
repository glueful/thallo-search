<?php

declare(strict_types=1);

namespace Thallo\Search\Index;

use Thallo\Contracts\Schema\ContentSchemaReader;
use Thallo\Contracts\Search\IndexableContent;

/**
 * Builds the shared-index search document for one published entry+locale.
 *
 * The `content` index has two searchable attributes, `title` (ranked first) and
 * `body`. Per-type `weights` cannot re-order index-global searchable attributes, so they
 * instead order the fields concatenated into `body` (highest weight first).
 */
final class DocumentBuilder
{
    private const INDEXABLE_TYPES = ['string', 'text'];

    /** @param array<string,array<string,mixed>> $typeConfig config('search.types') */
    public function __construct(private readonly array $typeConfig)
    {
    }

    /**
     * The Meilisearch document id for one entry+locale. Meilisearch ids allow only
     * alphanumerics, `-` and `_` — never use `:` or other separators here. Deletes
     * (MeilisearchBackend::deleteEntry) must compose the identical id.
     */
    public static function documentId(string $entryUuid, string $locale): string
    {
        return $entryUuid . '_' . $locale;
    }

    /** @return array<string,mixed> */
    public function build(IndexableContent $content, ContentSchemaReader $schema): array
    {
        $stringFields = $this->stringFieldNames($schema);
        $cfg = $this->typeConfig[$content->contentTypeSlug] ?? [];

        $exclude = array_map('strval', (array) ($cfg['exclude_fields'] ?? []));
        $selectable = array_values(array_diff($stringFields, $exclude));

        // Title.
        $titleField = isset($cfg['title_field']) ? (string) $cfg['title_field'] : 'title';
        $title = $this->stringValue($content->fields, $titleField);
        if (($title === null || $title === '') && $titleField === 'title') {
            // Convention chain: entryLabel → first indexed string field.
            $title = $content->entryLabel;
            if ($title === null || $title === '') {
                foreach ($selectable as $name) {
                    $v = $this->stringValue($content->fields, $name);
                    if ($v !== null && $v !== '') {
                        $title = $v;
                        break;
                    }
                }
            }
        }

        // Body field ordering.
        if (isset($cfg['body_fields'])) {
            $bodyFields = array_values(array_filter(
                array_map('strval', (array) $cfg['body_fields']),
                fn (string $f): bool => in_array($f, $selectable, true),
            ));
        } else {
            $bodyFields = array_values(array_filter($selectable, fn (string $f): bool => $f !== $titleField));
        }
        $bodyFields = $this->orderByWeight($bodyFields, (array) ($cfg['weights'] ?? []));

        // A body is indexed as the words a reader sees — a snippet is shown to a visitor, and a
        // tag name or a link target is not a word of the page.
        $bodyParts = [];
        foreach ($bodyFields as $name) {
            $v = $this->stringValue($content->fields, $name);
            if ($v === null || $v === '') {
                continue;
            }
            $v = match ($schema->field($name)?->format()) {
                'rich' => self::htmlToText($v),
                'plain' => self::markdownToText($v),
                default => $v,
            };
            if ($v !== '' && !self::isPathOrUrl($v)) {
                $bodyParts[] = $v;
            }
        }

        // No visibility flags are denormalized into the document: visibility is resolved
        // from the live type store at query time (VisibilityResolver), so flipping a type
        // private takes effect immediately without a reindex.
        return [
            'id' => self::documentId($content->entryUuid, $content->locale),
            'entry_uuid' => $content->entryUuid,
            'locale' => $content->locale,
            'content_type_uuid' => $content->contentTypeUuid,
            'content_type_slug' => $content->contentTypeSlug,
            'href' => $content->href,
            'title' => (string) ($title ?? ''),
            'body' => implode("\n\n", $bodyParts),
        ];
    }

    /**
     * The type slugs with per-type config — the single source for `search:status`'s
     * validation sweep (never re-read the config tree this builder was constructed from).
     *
     * @return list<string>
     */
    public function configuredTypeSlugs(): array
    {
        return array_map('strval', array_keys($this->typeConfig));
    }

    /** @return list<string> Non-fatal config warnings for `search:status`. */
    public function validate(string $typeSlug, ContentSchemaReader $schema): array
    {
        $cfg = $this->typeConfig[$typeSlug] ?? [];
        $warnings = [];

        $configured = [];
        if (isset($cfg['title_field'])) {
            $configured[] = (string) $cfg['title_field'];
        }
        foreach ((array) ($cfg['body_fields'] ?? []) as $f) {
            $configured[] = (string) $f;
        }
        foreach ((array) ($cfg['exclude_fields'] ?? []) as $f) {
            $configured[] = (string) $f;
        }

        foreach ($configured as $name) {
            $field = $schema->field($name);
            if ($field === null) {
                $warnings[] = "[{$typeSlug}] configured field '{$name}' does not exist in the schema (skipped).";
                continue;
            }
            if (!in_array($field->type(), self::INDEXABLE_TYPES, true)) {
                $warnings[] = "[{$typeSlug}] configured field '{$name}' is type '{$field->type()}', "
                    . 'not string/text (skipped).';
            }
        }

        return $warnings;
    }

    /** Rich text as its words: tags dropped (block ends become spaces), entities decoded. */
    private static function htmlToText(string $html): string
    {
        $text = (string) preg_replace('~<(?:/(?:p|div|h[1-6]|li|tr|blockquote|pre)|br\s*/?)>~i', ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Markdown kept in a plain text field (a docs page) as its words. Deliberately light — this
     * feeds an index, not a page: fence markers, heading and quote marks, table rules and pipes,
     * emphasis, task boxes and backticks go; a link or an image leaves its text and loses its
     * target. Plain prose passes through unchanged.
     */
    private static function markdownToText(string $markdown): string
    {
        $text = (string) preg_replace('/^ {0,3}(`{3,}|~{3,}).*$/m', '', $markdown);
        $rule = '[ \t]*:?-{3,}:?[ \t]*'; // a table's `|---|:---:|` line
        $text = (string) preg_replace('/^ {0,3}\|?' . $rule . '(\|' . $rule . ')*\|?[ \t]*$/m', '', $text);
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/^ {0,3}\[[^\]]+\]:\s+\S+.*$/m', '', $text);
        $text = (string) preg_replace('/^ {0,3}(?:#{1,6}|>+|[-*+]|\d+[.)])[ \t]+/m', '', $text);
        $text = (string) preg_replace('/^\[[ xX]\][ \t]+/m', '', $text);
        $text = str_replace(['|', '`', '**', '__', '~~'], [' ', '', '', '', ''], $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** A whole value that is one URL or one file path — where a page came from, not what it says. */
    private static function isPathOrUrl(string $value): bool
    {
        return preg_match('/\s/u', $value) !== 1
            && (preg_match('~\A[a-z][a-z0-9+.-]*://~i', $value) === 1 || str_contains($value, '/'));
    }

    /** @return list<string> */
    private function stringFieldNames(ContentSchemaReader $schema): array
    {
        $names = [];
        foreach ($schema->fields() as $field) {
            if (in_array($field->type(), self::INDEXABLE_TYPES, true)) {
                $names[] = $field->name();
            }
        }
        return $names;
    }

    /**
     * @param list<string> $fields
     * @param array<string,mixed> $weights
     * @return list<string>
     */
    private function orderByWeight(array $fields, array $weights): array
    {
        if ($weights === []) {
            return $fields;
        }
        // usort is stable (PHP ≥ 8.0), so equal-weight fields keep their input order.
        usort(
            $fields,
            fn (string $a, string $b): int => ((int) ($weights[$b] ?? 0)) <=> ((int) ($weights[$a] ?? 0)),
        );
        return $fields;
    }

    /** @param array<string,mixed> $fields */
    private function stringValue(array $fields, string $name): ?string
    {
        $v = $fields[$name] ?? null;
        return is_string($v) ? $v : null;
    }
}
