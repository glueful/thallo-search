<?php

declare(strict_types=1);

namespace Thallo\Search\Index;

use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Contracts\Search\ContentReindexer;
use Thallo\Contracts\Search\IndexableContentReader;
use Thallo\Search\Engine\SearchBackend;

/**
 * Reindexes a published entry/locale via the delivery-backed reader and the search backend.
 * locale === null (whole-entry delete) purges every locale doc; otherwise re-reads and
 * upserts, or deletes this locale's doc if the entry is no longer published/visible.
 */
final class SearchContentReindexer implements ContentReindexer
{
    private bool $indexEnsured = false;

    public function __construct(
        private readonly IndexableContentReader $reader,
        private readonly DocumentBuilder $builder,
        private readonly SearchBackend $backend,
        private readonly ContentTypeReader $types,
    ) {
    }

    public function reindexEntry(string $entryUuid, ?string $locale): void
    {
        if ($locale === null) {
            $this->backend->deleteEntry($entryUuid, null);
            return;
        }

        $record = $this->reader->getIndexablePublished($entryUuid, $locale);
        if ($record === null) {
            $this->backend->deleteEntry($entryUuid, $locale);
            return;
        }

        $schema = $this->types->schemaFor($record->contentTypeUuid);
        if ($schema === null) {
            $this->backend->deleteEntry($entryUuid, $locale);
            return;
        }

        // Guarantee the index exists WITH its searchable/filterable settings before the
        // first event-driven upsert: addDocuments auto-creates a settings-less index,
        // which would then reject every visibility-filtered search until a manual
        // search:reindex. Once per instance — ensureIndex is idempotent but not free.
        if (!$this->indexEnsured) {
            $this->backend->ensureIndex();
            $this->indexEnsured = true;
        }

        $this->backend->upsert([$this->builder->build($record, $schema)]);
    }
}
