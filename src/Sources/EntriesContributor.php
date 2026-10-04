<?php

declare(strict_types=1);

namespace Thallo\Search\Sources;

use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Contracts\Search\IndexableContentReader;
use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\ResultDisplay;
use Thallo\Contracts\Search\SearchAudience;
use Thallo\Contracts\Search\SearchDocument;
use Thallo\Contracts\Search\SearchDocumentPage;
use Thallo\Contracts\Search\SearchSourceContributor;
use Thallo\Search\Index\DocumentBuilder;
use Thallo\Search\Query\VisibilityResolver;

/**
 * Published entries as search results (search block spec §3.2). One document per published locale,
 * built by {@see DocumentBuilder}; enumeration pages whole entries; visibility is the content types
 * the audience may read; what is shown is read from the current published record through the
 * delivery reader, so an entry unpublished or edited since it was indexed is never shown stale.
 */
final class EntriesContributor implements SearchSourceContributor
{
    public const KIND = 'entries';
    private const TEXT_LIMIT = 20480;

    public function __construct(
        private readonly IndexableContentReader $reader,
        private readonly DocumentBuilder $builder,
        private readonly ContentTypeReader $types,
        private readonly VisibilityResolver $visibility,
    ) {
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Pages & posts';
    }

    public function requiredCapabilities(): array
    {
        return [];
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function documents(string $sourceId): array
    {
        $documents = [];
        foreach ($this->reader->publishedLocalesOf($sourceId) as $locale) {
            $record = $this->reader->getIndexablePublished($sourceId, $locale);
            $schema = $record === null ? null : $this->types->schemaFor($record->contentTypeUuid);
            if ($record === null || $schema === null) {
                continue;
            }
            $built = $this->builder->build($record, $schema);
            $documents[] = new SearchDocument(
                self::KIND,
                $sourceId,
                $locale,
                $record->contentTypeUuid,
                $record->href,
                (string) $built['title'],
                (string) $built['body'],
            );
        }
        return $documents;
    }

    public function enumerate(?string $after, int $size): SearchDocumentPage
    {
        $uuids = $this->reader->publishedEntryUuidsAfter($after, $size);
        $documents = [];
        foreach ($uuids as $uuid) {
            array_push($documents, ...$this->documents($uuid));
        }
        return new SearchDocumentPage($documents, count($uuids) === $size ? (string) end($uuids) : null);
    }

    public function visibilityFilter(SearchAudience $audience): KindFilter
    {
        $context = $this->visibility->resolve($audience->apiKeyScopes);
        return $context->allAccess ? KindFilter::all() : KindFilter::subtypes($context->visibleTypeUuids);
    }

    public function present(SearchAudience $audience, string $locale, array $sourceIds): array
    {
        $context = $this->visibility->resolve($audience->apiKeyScopes);
        $out = [];
        foreach ($sourceIds as $id) {
            $record = $this->reader->getIndexablePublished($id, $locale);
            $schema = $record === null ? null : $this->types->schemaFor($record->contentTypeUuid);
            if (
                $record === null || $schema === null || !$this->visibility->isTypeAccessible(
                    $context,
                    $record->contentTypeUuid,
                )
            ) {
                $out[$id] = null;
                continue;
            }
            $built = $this->builder->build($record, $schema);
            $out[$id] = new ResultDisplay(
                (string) $built['title'],
                $record->href,
                mb_strcut((string) $built['body'], 0, self::TEXT_LIMIT, 'UTF-8'),
            );
        }
        return $out;
    }
}
