<?php

declare(strict_types=1);

namespace Thallo\Search\Engine;

/**
 * The low-level Meilisearch primitives the search pack needs, as a pack-owned seam (search block
 * spec §3.3). Index names are given unprefixed; the live implementation applies the extension's
 * prefix. Document writes return the task uid without waiting — accepted is not completed, and a
 * caller confirms the outcome through {@see task()}. The live implementation is the only class that
 * imports the Meilisearch extension; tests use a fake.
 */
interface MeilisearchIndex
{
    /** The running server's version, e.g. `1.10.2`. */
    public function serverVersion(): string;

    /**
     * Create the index if absent and apply its settings, waiting for both.
     *
     * @param array<string, mixed> $settings
     */
    public function ensureIndex(string $uid, array $settings): void;

    /**
     * @param list<array<string, mixed>> $documents
     * @return int the task uid
     */
    public function addDocuments(string $uid, array $documents): int;

    /**
     * @param list<string> $ids
     * @return int the task uid
     */
    public function deleteDocuments(string $uid, array $ids): int;

    /** @return int the task uid */
    public function deleteByFilter(string $uid, string $filter): int;

    /** @return int the task uid */
    public function deleteIndex(string $uid): int;

    /** @return array{status: string, error: ?string} status: enqueued|processing|succeeded|failed|canceled */
    public function task(int $taskUid): array;

    /** @return list<string> unprefixed names of the indexes whose names start with `$prefix` */
    public function listIndexes(string $prefix): array;

    /**
     * One federated query across indexes, ranked together.
     *
     * @param list<array{indexUid: string, q: string, filter: string}> $queries
     * @return array{hits: list<array<string, mixed>>, estimatedTotalHits: int}
     * @throws IndexNotFound when a named index does not exist
     */
    public function federatedSearch(array $queries, int $limit, int $offset): array;


    public function reachable(string $uid): bool;
}
