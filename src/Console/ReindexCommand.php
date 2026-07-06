<?php

declare(strict_types=1);

namespace Thallo\Search\Console;

use Glueful\Console\BaseCommand;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Contracts\Search\IndexableContentReader;
use Thallo\Search\Engine\SearchBackend;
use Thallo\Search\Index\DocumentBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'search:reindex', description: 'Backfill the search index from published content.')]
final class ReindexCommand extends BaseCommand
{
    public function __construct(
        private readonly IndexableContentReader $reader,
        private readonly DocumentBuilder $builder,
        private readonly SearchBackend $backend,
        private readonly ContentTypeReader $types,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Limit to a content-type slug.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Limit to a locale.');
    }

    /** Ensure the index, page through published records, and upsert. Returns documents indexed. */
    public function backfill(?string $type, ?string $locale, int $pageSize = 200): int
    {
        $this->backend->ensureIndex();

        $offset = 0;
        $indexed = 0;
        // Memoize schemas per type: a site has a handful of types but thousands of records,
        // and schemaFor() runs an uncached content_types query per call.
        $schemas = [];
        do {
            $page = $this->reader->enumerateIndexablePublished($pageSize, $offset, $type, $locale);
            $docs = [];
            foreach ($page->items as $record) {
                if (!array_key_exists($record->contentTypeUuid, $schemas)) {
                    $schemas[$record->contentTypeUuid] = $this->types->schemaFor($record->contentTypeUuid);
                }
                $schema = $schemas[$record->contentTypeUuid];
                if ($schema === null) {
                    continue;
                }
                $docs[] = $this->builder->build($record, $schema);
            }
            if ($docs !== []) {
                $this->backend->upsert($docs);
                $indexed += count($docs);
            }
            $offset += $pageSize;
            // A short page means the result set is exhausted (IndexablePage carries no
            // total — paging this way costs zero COUNT queries).
        } while (count($page->items) === $pageSize);

        return $indexed;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->backend->health()) {
            $this->error('Search backend is unavailable — is Meilisearch running and configured?');
            return self::FAILURE;
        }

        $type = $input->getOption('type');
        $locale = $input->getOption('locale');
        $indexed = $this->backfill(
            is_string($type) ? $type : null,
            is_string($locale) ? $locale : null,
        );

        $this->success(sprintf('Indexed %d document(s).', $indexed));
        return self::SUCCESS;
    }
}
