<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

use Thallo\Search\Lifecycle\Fence;

/** Bound when no engine can answer: reads and writes fail with the reason, readiness says why. */
final class UnavailableIndexStore implements IndexStore
{
    public function __construct(private readonly string $reason)
    {
    }

    public function write(Target $target, array $documents, Fence $fence): array
    {
        throw new \RuntimeException('Search is unavailable: ' . $this->reason);
    }

    public function replaceSource(array $targets, string $kind, string $sourceId, array $documents, Fence $fence): array
    {
        throw new \RuntimeException('Search is unavailable: ' . $this->reason);
    }

    public function sweep(string $kind, int $belowGeneration, Fence $fence): void
    {
    }

    public function confirm(TargetReceipt $receipt): ReceiptOutcome
    {
        return new ReceiptOutcome(ConfirmResult::FAILED);
    }

    public function search(array $targets, StoreQuery $query): StoreResult
    {
        throw new \RuntimeException('Search is unavailable: ' . $this->reason);
    }

    public function createTarget(Target $target): void
    {
        throw new \RuntimeException('Search is unavailable: ' . $this->reason);
    }

    public function dropTarget(Target $target): void
    {
    }

    public function listTargets(string $prefix): array
    {
        return [];
    }

    public function readiness(): Readiness
    {
        return new Readiness(false, $this->reason);
    }
}
