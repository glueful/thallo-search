<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Glueful\Database\Connection;
use Thallo\Search\Query\KindAvailability;

/**
 * Asks for a rebuild (search block spec §3.5.7). The demand rows are written in the requester's own
 * transaction; the wake-up is queued only after it commits, so a rolled-back request queues
 * nothing. If queueing fails, the demand is still recorded — the scheduled reconcile picks it up —
 * and the failure is noted where the Search panel shows it.
 */
final class SearchDemand
{
    /** @param \Closure(array<string, mixed>): void $push queues the wake-up job with its data */
    public function __construct(
        private readonly StateRepository $state,
        private readonly KindAvailability $availability,
        private readonly Connection $db,
        private readonly Workspace $workspace,
        private readonly \Closure $push,
    ) {
    }

    /**
     * @param string|null $kind one kind, or null for every available kind
     * @return array{recorded: true, kinds: list<string>}
     */
    public function request(?string $kind, string $reason): array
    {
        $kinds = $kind !== null ? [$kind] : array_keys($this->availability->available());
        foreach ($kinds as $one) {
            $this->state->addDemand($one, $reason);
        }
        $workspace = $this->workspace->current();
        $this->db->afterCommit(function () use ($kinds, $workspace): void {
            try {
                ($this->push)(['workspace' => $workspace]);
            } catch (\Throwable $e) {
                foreach ($kinds as $one) {
                    $this->state->noteQueueFailure($one, $e->getMessage());
                }
            }
        });
        return ['recorded' => true, 'kinds' => $kinds];
    }
}
