<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Psr\Log\LoggerInterface;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Search\Query\KindAvailability;

/**
 * Picks up outstanding rebuild demand (search block spec §3.5.7). The queued wake-up, the scheduled
 * `search:reconcile` and a throttled boot recovery all come here; the demand itself lives in the
 * database, so a lost wake-up loses nothing. A full reconcile rebuilds every available kind even
 * when it reports ready — what catches a change lost between a commit and its after-commit event.
 * Versions and satisfied demand are written by a promoted build alone; nothing here acknowledges
 * demand a build did not satisfy.
 */
final class Reconciler
{
    /** Installation-wide: the kinds last seen available, for changes made only in configuration. */
    public const AVAILABILITY_MARKER = 'search.availability_marker';

    /** Installation-wide: set once the old shared Meilisearch index has been deleted. */
    public const OLD_INDEX_FLAG = 'search.old_index';

    public function __construct(
        private readonly KindAvailability $availability,
        private readonly DemandResolver $demand,
        private readonly StateRepository $state,
        private readonly Rebuilder $rebuilder,
        private readonly IndexRetirement $retirement,
        private readonly Drainer $drainer,
        private readonly Workspace $workspace,
        private readonly SystemChannel $flags,
        private readonly LoggerInterface $logger,
        /** Queues a wake-up for a workspace (search block spec §3.5.7): `['workspace' => ?string]`. */
        private readonly ?\Closure $wake = null,
        private readonly ?WakeGate $gate = null,
    ) {
    }

    /** @return array<string, RebuildOutcome|null> kind => what happened (null: nothing was due) */
    public function runWorkspace(bool $full): array
    {
        $outcomes = [];
        foreach (array_keys($this->availability->available()) as $kind) {
            $outcomes[$kind] = $this->runKind($kind, $full);
        }
        return $outcomes;
    }

    public function runKind(string $kind, bool $full = false): ?RebuildOutcome
    {
        $reason = $full ? 'full' : $this->demand->pending($kind);
        $this->state->ensure($kind);
        $outcome = null;
        if ($reason !== null) {
            $outcome = $this->rebuilder->run($kind);
            if ($outcome !== RebuildOutcome::PROMOTED && $outcome !== RebuildOutcome::BUSY) {
                $this->logger->warning("Search rebuild of '{$kind}' ({$reason}) did not complete: {$outcome->value}");
            }
        }
        $this->retirement->collect($kind);
        $this->drainer->drain($kind);
        return $outcome;
    }

    /**
     * The queued drain of live changes (search block spec §3.5.2): reopen the gate first, so a change
     * made from now on queues the next drain, then drain batch after batch until the backlog is empty
     * or stops shrinking (another drainer holds the lease, or entries keep failing).
     */
    public function drainBacklog(string $kind, int $maxRounds = 200): void
    {
        $this->gate?->release($this->workspace->current(), $kind);
        $left = count($this->state->unresolvedEntries($kind));
        for ($round = 0; $round < $maxRounds && $left > 0; $round++) {
            $this->drainer->drain($kind);
            $now = count($this->state->unresolvedEntries($kind));
            if ($now >= $left) {
                return;
            }
            $left = $now;
        }
    }

    /** Every workspace, each inside its own context — after noting any change in availability. */
    public function runAll(bool $full): void
    {
        try {
            $this->noteAvailabilityChange();
        } catch (\Throwable $e) {
            $this->logger->warning('Search availability check failed: ' . ErrorText::sanitize($e->getMessage()));
        }
        if ($this->availability->available() === []) {
            return; // Search is off: nothing to build, so no workspace is walked
        }
        $this->workspace->each(function () use ($full): void {
            try {
                $this->runWorkspace($full);
            } catch (\Throwable $e) {
                $message = ErrorText::sanitize($e->getMessage());
                $this->logger->warning('Search reconcile failed for a workspace: ' . $message);
            }
        });
        $this->dropOldSharedIndexOnce();
    }

    /** The old shared Meilisearch index goes once; a failed delete is retried on the next run. */
    private function dropOldSharedIndexOnce(): void
    {
        if ($this->flags->get(self::OLD_INDEX_FLAG) !== null) {
            return;
        }
        try {
            if ($this->retirement->dropOldSharedIndex()) {
                $this->flags->put(self::OLD_INDEX_FLAG, 'deleted');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Search old index not deleted: ' . ErrorText::sanitize($e->getMessage()));
        }
    }

    /**
     * Boot recovery: note any change in availability, then queue a wake-up for every workspace with
     * outstanding demand. It never rebuilds in the request that booted; the queue worker or the
     * scheduled `search:reconcile` does the work.
     */
    public function recoverIfDue(): void
    {
        $this->noteAvailabilityChange();
        if ($this->wake === null) {
            return;
        }
        $this->workspace->each(function (?string $workspace): void {
            foreach (array_keys($this->availability->available()) as $kind) {
                if ($this->demand->pending($kind) !== null) {
                    ($this->wake)(['workspace' => $workspace]);
                    return;
                }
            }
        });
    }

    /**
     * A change in which kinds are available — including one made only in configuration, with no
     * switch written — becomes capability demand in every workspace.
     */
    private function noteAvailabilityChange(): void
    {
        if (method_exists($this->flags, 'clearCache')) {
            $this->flags->clearCache();
        }
        $available = array_keys($this->availability->available());
        sort($available);
        $marker = json_encode($available, JSON_THROW_ON_ERROR);
        $last = $this->flags->get(self::AVAILABILITY_MARKER);
        if ($last === $marker) {
            return;
        }
        $previous = is_string($last) ? (array) json_decode($last, true) : [];
        // First sight: new-workspace demand already covers the first build.
        $newly = $last === null ? [] : array_values(array_diff($available, $previous));
        $this->workspace->each(function () use ($newly): void {
            foreach ($newly as $kind) {
                $this->state->addDemand($kind, 'capability');
            }
        });
        $this->flags->put(self::AVAILABILITY_MARKER, $marker);
    }
}
