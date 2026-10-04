<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Glueful\Database\Connection;
use Psr\Log\LoggerInterface;
use Thallo\Contracts\Search\SearchIndex;

/**
 * How packs report changes while search is on (search block spec §3.5.2). A change is journaled
 * under the kind's row lock, then drained after the caller's transaction commits. Nothing here ever
 * fails the caller's request: a failure leaves the kind `out_of_date`, and the scheduled reconcile
 * repairs it.
 */
final class LiveSearchIndex implements SearchIndex
{
    public function __construct(
        private readonly StateRepository $state,
        private readonly Drainer $drainer,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function changed(string $kind, string $sourceId): void
    {
        try {
            $this->state->appendChange($kind, $sourceId);
        } catch (\Throwable $e) {
            $this->logger->warning('Search change not journaled: ' . ErrorText::sanitize($e->getMessage()));
            return;
        }
        $this->db->afterCommit(function () use ($kind): void {
            try {
                $this->drainer->drain($kind);
            } catch (\Throwable $e) {
                $message = ErrorText::sanitize($e->getMessage());
                $this->logger->warning('Search index update failed: ' . $message);
                try {
                    $this->state->markOutOfDate($kind, $message);
                } catch (\Throwable) {
                }
            }
        });
    }

    public function kindChanged(string $kind, string $reason): void
    {
        try {
            $this->state->addDemand($kind, $reason);
        } catch (\Throwable $e) {
            $this->logger->warning('Search rebuild demand not recorded: ' . ErrorText::sanitize($e->getMessage()));
        }
    }
}
