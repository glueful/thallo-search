<?php

declare(strict_types=1);

namespace Thallo\Search\Engine;

/**
 * The low-level Meilisearch primitives the backend needs, as a pack-owned seam. The live
 * implementation is the ONLY class that imports the Meilisearch extension; tests use a fake.
 */
interface MeilisearchIndex
{
    /** @param array<string,mixed> $settings */
    public function ensureIndex(array $settings): void;

    /** @param list<array<string,mixed>> $documents */
    public function addDocuments(array $documents): void;

    public function deleteDocument(string $id): void;

    /** Delete every document matching a Meilisearch filter expression. */
    public function deleteByFilter(string $filter): void;

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed> raw Meilisearch result (hits, estimatedTotalHits, …)
     */
    public function rawSearch(string $query, array $params): array;

    public function reachable(): bool;
}
