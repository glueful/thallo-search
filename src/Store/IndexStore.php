<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

use Thallo\Contracts\Search\SearchDocument;
use Thallo\Search\Lifecycle\Fence;

/**
 * The engine port of the search index (search block spec §3.3–§3.5). Every write is fenced; every
 * write returns one {@see TargetReceipt} per target, the same shape on both engines; a target is
 * acknowledged only from a receipt whose {@see confirm()} is SUCCEEDED.
 */
interface IndexStore
{
    /**
     * A build batch into one target. Its documents are all new, so it never deletes.
     *
     * @param list<SearchDocument> $documents
     * @return list<TargetReceipt>
     */
    public function write(Target $target, array $documents, Fence $fence): array;

    /**
     * Make each target hold exactly `$documents` for one source: retained locales are upserted,
     * absent ones deleted, and an empty list removes the source.
     *
     * @param list<Target> $targets
     * @param list<SearchDocument> $documents
     * @return list<TargetReceipt>
     */
    public function replaceSource(
        array $targets,
        string $kind,
        string $sourceId,
        array $documents,
        Fence $fence,
    ): array;

    /** Delete this kind's documents written before `$belowGeneration` (Postgres). */
    public function sweep(string $kind, int $belowGeneration, Fence $fence): void;

    public function confirm(TargetReceipt $receipt): ReceiptOutcome;

    /** @param list<Target> $targets */
    public function search(array $targets, StoreQuery $query): StoreResult;

    public function createTarget(Target $target): void;

    public function dropTarget(Target $target): void;

    /** @return list<string> physical target names with this prefix */
    public function listTargets(string $prefix): array;

    public function readiness(): Readiness;
}
