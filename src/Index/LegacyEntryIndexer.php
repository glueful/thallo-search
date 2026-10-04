<?php

declare(strict_types=1);

namespace Thallo\Search\Index;

use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Contracts\Search\IndexableContentReader;
use Thallo\Search\Engine\SearchBackend;
use Thallo\Search\Lifecycle\StateRepository;
use Thallo\Search\Sources\EntriesContributor;

/**
 * Keeps the legacy entries index current until the workspace cuts over (search block spec §3.5.6):
 * before the flip, queries read the old documents, so live entry changes must keep reaching them.
 * Once the workspace's format is `v2`, it does nothing. Re-reads a published entry/locale and
 * upserts it, deletes this locale's document if it is no longer published, and purges every locale
 * for a whole-entry delete.
 */
final class LegacyEntryIndexer
{
    private bool $indexEnsured = false;

    public function __construct(
        private readonly IndexableContentReader $reader,
        private readonly DocumentBuilder $builder,
        private readonly SearchBackend $backend,
        private readonly ContentTypeReader $types,
        private readonly StateRepository $state,
    ) {
    }

    public function reindexEntry(string $entryUuid, ?string $locale): void
    {
        if (($this->state->row(EntriesContributor::KIND)['format'] ?? 'legacy') === 'v2') {
            return;
        }
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
