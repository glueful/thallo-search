<?php

declare(strict_types=1);

namespace Thallo\Search\Engine;

use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Meilisearch\Endpoints\Indexes;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The ONLY class in this pack that imports Glueful\Extensions\Meilisearch\*. Wraps the
 * extension's IndexManager (lifecycle/settings/stats) and the raw index endpoint
 * (documents + search) for the pack-owned MeilisearchIndex seam.
 */
final class LiveMeilisearchIndex implements MeilisearchIndex
{
    private ?Indexes $handle = null;

    public function __construct(
        private readonly IndexManager $manager,
        private readonly string $indexName,
    ) {
    }

    public static function fromContainer(ContainerInterface $container, string $indexName): self
    {
        return new self($container->get(IndexManager::class), $indexName);
    }

    public function ensureIndex(array $settings): void
    {
        $this->handle = $this->manager->getOrCreateIndex($this->indexName);
        $this->manager->updateSettings($this->indexName, $settings);
    }

    public function addDocuments(array $documents): void
    {
        // 'id' is the Meilisearch primary key by convention (see IndexManager::createIndex).
        $this->index()->addDocuments($documents, 'id');
    }

    public function deleteDocument(string $id): void
    {
        $this->index()->deleteDocument($id);
    }

    public function deleteByFilter(string $filter): void
    {
        // meilisearch-php: filtered delete via deleteDocuments(['filter' => …]).
        $this->index()->deleteDocuments(['filter' => $filter]);
    }

    public function rawSearch(string $query, array $params): array
    {
        // rawSearch returns the direct Meilisearch response array (hits with _formatted /
        // _rankingScore, estimatedTotalHits) — no SearchResult wrapper.
        return $this->index()->rawSearch($query, $params);
    }

    /**
     * Memoized index handle: getOrCreateIndex() re-validates existence/primary key with an
     * HTTP GET on every call, which would double the Meilisearch traffic of each operation.
     */
    private function index(): Indexes
    {
        return $this->handle ??= $this->manager->getOrCreateIndex($this->indexName);
    }

    public function reachable(): bool
    {
        try {
            $this->manager->getStats($this->indexName);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
