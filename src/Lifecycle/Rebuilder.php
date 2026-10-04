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
 * One rebuild of a kind (search block spec §3.5.3–§3.5.4). Claim a generation unique to this
 * attempt; enumerate every listed item from the start into this attempt's own target, persisting the
 * cursor only after a batch is confirmed; replay everything journaled since the claim plus every
 * unresolved entry; promote under the row lock journal appends also take, once every relevant entry
 * is acknowledged on the build target; then, under the promoted fence, sweep or retire and record
 * completion with the values captured when the attempt began. A takeover restarts; it never resumes
 * an abandoned target or cursor.
 */
final class Rebuilder
{
    private const PROMOTION_ROUNDS = 5;

    public function __construct(
        private readonly StateRepository $state,
        private readonly IndexStore $store,
        private readonly SearchSourceRegistry $sources,
        private readonly SearchIndexLocator $locator,
        private readonly DemandResolver $demand,
        private readonly IndexRetirement $retirement,
        private readonly int $leaseSeconds,
        private readonly int $batchSize,
        private readonly LoggerInterface $logger,
        /** A test seam: called as ($point, $batch) at 'beforeWrite', 'afterBatch' and 'beforePromote'. */
        private readonly ?\Closure $hook = null,
    ) {
    }

    public function run(string $kind): RebuildOutcome
    {
        $contributor = $this->sources->all()[$kind] ?? null;
        if ($contributor === null) {
            return RebuildOutcome::FAILED;
        }
        $claim = $this->state->claimBuild(
            $kind,
            $this->leaseSeconds,
            $this->demand->relevantVersion($kind),
            $contributor->schemaVersion(),
        );
        if ($claim === null) {
            return RebuildOutcome::BUSY;
        }
        $fence = Fence::builder($kind, $claim->token, $claim->generation);
        $target = $this->locator->buildTarget($kind, $claim->generation);
        $previous = $this->locator->targets($kind)['active'];

        try {
            $this->store->createTarget($target);
            $this->state->recordBuildTarget($fence, $target->name);
            for ($after = null, $batch = 0;; $batch++) {
                $page = $contributor->enumerate($after, $this->batchSize);
                $this->hook('beforeWrite', $batch);
                [$receipt] = $this->store->write($target, $page->documents, $fence);
                if ($this->store->confirm($receipt)->result !== ConfirmResult::SUCCEEDED) {
                    $this->state->abandonBuild($fence, 'failed', 'A batch of the rebuild did not complete.');
                    return RebuildOutcome::FAILED; // no sweep, no promotion
                }
                $this->state->advanceCursor($fence, $page->nextAfter, count($page->documents));
                $this->hook('afterBatch', $batch);
                if ($page->nextAfter === null) {
                    break;
                }
                $after = $page->nextAfter;
                $this->state->renewBuild($fence, $this->leaseSeconds);
            }

            $this->replay($fence, $target, $this->state->unresolvedEntries($kind, $claim->journalStartSeq));
            $this->hook('beforePromote');
            if (!$this->promote($fence, $target, $claim)) {
                $this->state->abandonBuild($fence, 'failed', 'The rebuild could not catch up with live changes.');
                return RebuildOutcome::FAILED;
            }

            $promoted = Fence::promoted($kind, $claim->generation);
            if ($this->locator->engine() === SearchIndexLocator::POSTGRES) {
                $this->store->sweep($kind, $claim->generation, $promoted);
            } elseif ($previous !== null && $previous->name !== $target->name) {
                $this->retirement->retire($kind, $previous);
            }
            $this->state->satisfy(
                $promoted,
                $claim->demandSeqAtStart,
                $claim->versionAtStart,
                $claim->schemaVersionAtStart,
            );
            return RebuildOutcome::PROMOTED;
        } catch (StaleFence) {
            return RebuildOutcome::LOST;
        } catch (\Throwable $e) {
            $message = ErrorText::sanitize($e->getMessage());
            $this->logger->warning("Search rebuild of '{$kind}' failed: {$message}");
            try {
                $this->state->abandonBuild($fence, 'failed', $message);
            } catch (StaleFence) {
                return RebuildOutcome::LOST;
            }
            return RebuildOutcome::FAILED;
        }
    }

    /** Promote once every relevant journal entry is acknowledged on the build target. */
    private function promote(Fence $fence, Target $target, Claim $claim): bool
    {
        for ($round = 0; $round < self::PROMOTION_ROUNDS; $round++) {
            $missing = $this->state->promote($fence, $target, $claim->journalStartSeq);
            if ($missing === null) {
                return true;
            }
            $this->replay($fence, $target, $this->state->entries($claim->kind, $missing));
        }
        return false;
    }

    /**
     * Re-read each entry's item and replace it on the build target; acknowledge only a confirmed
     * success. A target whose earlier tasks are still running gets no replacement.
     *
     * @param list<JournalEntry> $entries
     */
    private function replay(Fence $fence, Target $target, array $entries): void
    {
        $contributor = $this->sources->all()[$fence->kind] ?? null;
        foreach ($entries as $entry) {
            $ack = $this->state->ack($entry->kind, $entry->seq, $target->key());
            if ($ack !== null && $ack['status'] === 'pending' && $ack['task_uids'] !== []) {
                if (
                    $this->record(
                        $fence,
                        $entry,
                        new TargetReceipt($target->key(), $ack['task_uids']),
                    ) === ConfirmResult::PENDING
                ) {
                    continue;
                }
            }
            $documents = $contributor === null ? [] : $contributor->documents($entry->sourceId);
            foreach (
                $this->store->replaceSource(
                    [$target],
                    $entry->kind,
                    $entry->sourceId,
                    $documents,
                    $fence,
                ) as $receipt
            ) {
                $this->state->recordAck($fence, $entry->seq, $receipt->targetKey, $receipt->taskUids, 'pending');
                $this->record($fence, $entry, $receipt);
            }
        }
    }

    private function record(Fence $fence, JournalEntry $entry, TargetReceipt $receipt): ConfirmResult
    {
        $outcome = $this->store->confirm($receipt);
        $uids = $outcome->result === ConfirmResult::PENDING ? $outcome->outstandingTaskUids : $receipt->taskUids;
        $this->state->recordAck($fence, $entry->seq, $receipt->targetKey, $uids, $outcome->result->value);
        return $outcome->result;
    }

    private function hook(string $point, int $batch = 0): void
    {
        if ($this->hook !== null) {
            ($this->hook)($point, $batch);
        }
    }
}
