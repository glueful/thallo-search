<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Psr\Log\LoggerInterface;
use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Search\Store\ConfirmResult;
use Thallo\Search\Store\IndexStore;
use Thallo\Search\Store\Target;
use Thallo\Search\Store\TargetReceipt;

/**
 * Applies journaled live changes to every current target (search block spec §3.5.2, §3.5.5). One
 * drainer per workspace and kind holds a lease. For each entry it reads the current targets, first
 * resolves any target whose earlier tasks are still recorded as pending (no replacement goes out
 * while they could still run), replaces the source on the targets that still lack it, and
 * acknowledges a target only from a receipt that confirmed SUCCEEDED. An entry resolves only when
 * every current target — re-read under the lock — has its acknowledgement.
 *
 * It sends a write only while its lease has more than the request timeout plus a margin left, so a
 * takeover that waits out the lease plus quiescence comes after everything this drainer sent.
 */
final class Drainer
{
    private const ROUNDS = 3;

    public function __construct(
        private readonly StateRepository $state,
        private readonly IndexStore $store,
        private readonly SearchSourceRegistry $sources,
        private readonly SearchIndexLocator $locator,
        private readonly int $leaseSeconds,
        private readonly int $requestTimeoutSeconds,
        private readonly int $marginSeconds,
        private readonly LoggerInterface $logger,
        /** A test seam: runs after each read of the current targets. */
        private readonly ?\Closure $afterTargetRead = null,
    ) {
    }

    public function drain(string $kind, int $budget = 50): void
    {
        $token = $this->state->claimDrainer(
            $kind,
            $this->leaseSeconds,
            $this->requestTimeoutSeconds + $this->marginSeconds,
        );
        if ($token === null) {
            return; // another drainer holds the lease
        }
        $fence = Fence::drainer($kind, $token);
        try {
            foreach (array_slice($this->state->unresolvedEntries($kind), 0, $budget) as $entry) {
                if (!$this->apply($fence, $entry)) {
                    break; // the lease is running out
                }
            }
        } catch (StaleFence) {
            return; // replaced; the new drainer carries on
        } finally {
            try {
                $this->state->releaseDrainer($fence);
            } catch (StaleFence) {
            }
        }
    }

    /** @return bool false when the lease no longer allows another send */
    private function apply(Fence $fence, JournalEntry $entry): bool
    {
        try {
            for ($round = 0; $round < self::ROUNDS; $round++) {
                $targets = $this->locator->currentTargets($entry->kind);
                if ($this->afterTargetRead !== null) {
                    ($this->afterTargetRead)();
                }
                $missing = [];
                $blocked = false;
                foreach ($targets as $key => $target) {
                    $state = $this->resolvePending($fence, $entry, $key);
                    if ($state === 'succeeded') {
                        continue;
                    }
                    if ($state === 'pending') {
                        $blocked = true;
                        continue;
                    }
                    $missing[$key] = $target;
                }
                if ($missing === []) {
                    if ($blocked) {
                        return true; // wait for the running tasks; never replace them
                    }
                    if ($this->state->resolveIfComplete($fence, $entry->seq)) {
                        return true;
                    }
                    continue; // a target appeared under the lock; go round again
                }
                if ($this->state->drainerSecondsLeft($fence) <= $this->requestTimeoutSeconds + $this->marginSeconds) {
                    return false;
                }
                $contributor = $this->sources->all()[$entry->kind] ?? null;
                $documents = $contributor === null ? [] : $contributor->documents($entry->sourceId);
                $receipts = $this->store->replaceSource(
                    array_values($missing),
                    $entry->kind,
                    $entry->sourceId,
                    $documents,
                    $fence,
                );
                foreach ($receipts as $receipt) {
                    $this->state->recordAck($fence, $entry->seq, $receipt->targetKey, $receipt->taskUids, 'pending');
                    $this->record($fence, $entry, $receipt);
                }
                if ($this->state->resolveIfComplete($fence, $entry->seq)) {
                    return true;
                }
            }
            $this->state->markOutOfDate(
                $entry->kind,
                "A change to '{$entry->sourceId}' did not reach every search index.",
            );
        } catch (StaleFence $e) {
            throw $e;
        } catch (\Throwable $e) {
            $message = ErrorText::sanitize($e->getMessage());
            $this->logger->warning('Search index update failed: ' . $message);
            $this->state->markEntryFailed($entry->kind, $entry->seq, $message);
            $this->state->markOutOfDate($entry->kind, $message);
        }
        return true;
    }

    /**
     * Where a target stands for this entry, confirming any tasks still recorded as pending first.
     *
     * @return string 'succeeded', 'pending' (tasks still running) or 'missing'
     */
    private function resolvePending(Fence $fence, JournalEntry $entry, string $key): string
    {
        $ack = $this->state->ack($entry->kind, $entry->seq, $key);
        if ($ack === null || $ack['status'] === 'failed') {
            return 'missing';
        }
        if ($ack['status'] === 'succeeded') {
            return 'succeeded';
        }
        if ($ack['task_uids'] === []) {
            return 'missing';
        }
        $result = $this->record($fence, $entry, new TargetReceipt($key, $ack['task_uids']));
        return match ($result) {
            ConfirmResult::SUCCEEDED => 'succeeded',
            ConfirmResult::PENDING => 'pending',
            ConfirmResult::FAILED => 'missing',
        };
    }

    /** Confirm a receipt and record its outcome; pending keeps only the tasks still running. */
    private function record(Fence $fence, JournalEntry $entry, TargetReceipt $receipt): ConfirmResult
    {
        $outcome = $this->store->confirm($receipt);
        $uids = $outcome->result === ConfirmResult::PENDING ? $outcome->outstandingTaskUids : $receipt->taskUids;
        $this->state->recordAck($fence, $entry->seq, $receipt->targetKey, $uids, $outcome->result->value);
        if ($outcome->result === ConfirmResult::FAILED) {
            // A failed live update is visible at once; the entry stays unresolved and is retried.
            $this->state->markOutOfDate(
                $entry->kind,
                "A change to '{$entry->sourceId}' failed on a search index; it will be retried.",
            );
        }
        return $outcome->result;
    }

    /** @return array<string, Target> */
    public function targetsOf(string $kind): array
    {
        return $this->locator->currentTargets($kind);
    }
}
