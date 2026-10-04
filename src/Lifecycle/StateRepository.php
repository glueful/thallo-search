<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Glueful\Database\Connection;
use Thallo\Search\Store\Target;

/**
 * The only writer of the search index's per-kind state (search block spec §3.5.1–§3.5.5): the build
 * claim and lease, the drainer's lease, the change journal, acknowledgements, demand and status.
 *
 * Every lease decision follows one rule: take the row lock first, then read the clock and the row,
 * then decide. The lock is taken by updating the row's `locked_at` (the query builder has no
 * `FOR UPDATE`), which holds the row's write lock until the transaction ends; the clock is the
 * database's current time. A wait for the lock, or a long outer transaction, therefore never lets
 * an expired holder through: the check runs after both, against a fresh row and a fresh time.
 *
 * Every statement goes through the query builder, so the tenancy hook scopes and stamps each one to
 * the current workspace.
 */
final class StateRepository
{
    private const STATE = 'search_index_state';
    private const CHANGES = 'search_index_changes';
    private const ACKS = 'search_index_acks';
    private const DEMAND = 'search_index_demand';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> the kind's row, created (pending, legacy) if missing */
    public function ensure(string $kind): array
    {
        $row = $this->row($kind);
        if ($row !== null) {
            return $row;
        }
        try {
            $this->db->table(self::STATE)->insert([
                'kind' => $kind, 'status' => 'pending', 'format' => 'legacy', 'updated_at' => $this->clock->now(),
            ]);
        } catch (\Throwable $e) {
            // A concurrent ensure() won the unique index; its row is the row.
            if ($this->row($kind) === null) {
                throw $e;
            }
        }
        return (array) $this->row($kind);
    }

    /** @return array<string, mixed>|null */
    public function row(string $kind): ?array
    {
        return $this->db->table(self::STATE)->where('kind', '=', $kind)->first();
    }

    /**
     * Run `$fn($row, $now)` holding the kind's row lock, with the row and the time read after the
     * lock was taken.
     */
    public function locked(string $kind, callable $fn): mixed
    {
        $this->ensure($kind);
        return $this->db->transaction(function () use ($kind, $fn) {
            [$row, $now] = $this->lock($kind);
            return $fn($row, $now);
        });
    }

    /** As {@see locked()}, refusing — before `$fn` runs — a writer that no longer holds `$fence`. */
    public function fenced(Fence $fence, callable $fn): mixed
    {
        return $this->db->transaction(function () use ($fence, $fn) {
            [$row, $now] = $this->lock($fence->kind);
            if (!$this->holds($fence, $row, $now)) {
                throw new StaleFence("Fence for '{$fence->kind}' ({$fence->role}) is no longer held.");
            }
            return $fn($row, $now);
        });
    }

    /** Journal a change under the row lock that promotion also takes; returns its seq. */
    public function appendChange(string $kind, string $sourceId): int
    {
        return (int) $this->locked($kind, function (array $row, string $now) use ($kind, $sourceId): int {
            $seq = (int) $row['journal_head'] + 1;
            $this->db->table(self::STATE)->where('kind', '=', $kind)->update(['journal_head' => $seq]);
            $this->db->table(self::CHANGES)->insert([
                'kind' => $kind, 'source_id' => $sourceId, 'seq' => $seq, 'resolved' => 0, 'created_at' => $now,
            ]);
            return $seq;
        });
    }

    public function claimBuild(string $kind, int $leaseSeconds, int $versionAtStart, int $schemaVersionAtStart): ?Claim
    {
        return $this->locked($kind, function (
            array $row,
            string $now
        ) use (
            $kind,
            $leaseSeconds,
            $versionAtStart,
            $schemaVersionAtStart,
        ): ?Claim {
            if ($row['owner_token'] !== null && (string) $row['lease_until'] > $now) {
                return null;
            }
            $token = bin2hex(random_bytes(16));
            $generation = (int) $row['generation_counter'] + 1;
            $journalStart = (int) $row['journal_head'];
            $demand = $this->maxDemandSeq($kind);
            $this->db->table(self::STATE)->where('kind', '=', $kind)->update([
                'generation_counter' => $generation,
                'building_generation' => $generation,
                'building_target' => null,
                'owner_token' => $token,
                'lease_until' => self::plus($now, $leaseSeconds),
                'cursor' => null,
                'processed' => 0,
                'journal_start_seq' => $journalStart,
                'demand_seq_at_start' => $demand,
                'status' => 'building',
                'updated_at' => $now,
            ]);
            return new Claim(
                $kind,
                $token,
                $generation,
                $journalStart,
                $demand,
                $versionAtStart,
                $schemaVersionAtStart,
            );
        });
    }

    public function renewBuild(Fence $fence, int $leaseSeconds): void
    {
        $this->fenced($fence, function (array $row, string $now) use ($fence, $leaseSeconds): void {
            $this->update($fence->kind, ['lease_until' => self::plus($now, $leaseSeconds)]);
        });
    }

    public function releaseBuild(Fence $fence): void
    {
        $this->fenced($fence, function () use ($fence): void {
            $this->update($fence->kind, ['owner_token' => null, 'lease_until' => null]);
        });
    }

    /** Sets the building target (fenced), so drainers include it from now on. */
    public function recordBuildTarget(Fence $fence, string $target): void
    {
        $this->fenced($fence, fn () => $this->update($fence->kind, ['building_target' => $target]));
    }

    public function advanceCursor(Fence $fence, ?string $after, int $count): void
    {
        $this->fenced($fence, function (array $row) use ($fence, $after, $count): void {
            $this->update($fence->kind, ['cursor' => $after, 'processed' => (int) $row['processed'] + $count]);
        });
    }

    /** Completion, under the promoted fence, with the values the claim captured when it began. */
    public function satisfy(Fence $promoted, int $demandSeq, int $reconciledVersion, int $schemaVersion): void
    {
        $this->fenced($promoted, function () use ($promoted, $demandSeq, $reconciledVersion, $schemaVersion): void {
            $this->update($promoted->kind, [
                'satisfied_seq' => $demandSeq,
                'reconciled_version' => $reconciledVersion,
                'schema_version' => $schemaVersion,
            ]);
        });
    }

    /** Claim the kind's drainer lease; a takeover waits until the old lease plus quiescence has passed. */
    public function claimDrainer(string $kind, int $leaseSeconds, int $quiescenceSeconds): ?string
    {
        $claim = function (array $row, string $now) use ($kind, $leaseSeconds, $quiescenceSeconds): ?string {
            $quietAt = self::plus((string) $row['drainer_lease_until'], $quiescenceSeconds);
            if ($row['drainer_token'] !== null && $quietAt > $now) {
                return null;
            }
            $token = bin2hex(random_bytes(16));
            $this->update($kind, ['drainer_token' => $token, 'drainer_lease_until' => self::plus($now, $leaseSeconds)]);
            return $token;
        };

        return $this->locked($kind, $claim);
    }

    public function releaseDrainer(Fence $fence): void
    {
        $this->fenced(
            $fence,
            fn () => $this->update($fence->kind, ['drainer_token' => null, 'drainer_lease_until' => null]),
        );
    }

    /** The drainer lease's remaining seconds, by the database clock (0 once it has ended). */
    public function drainerSecondsLeft(Fence $fence): int
    {
        $row = $this->row($fence->kind);
        if ($row === null || $row['drainer_token'] !== $fence->token) {
            return 0;
        }
        $left = strtotime((string) $row['drainer_lease_until'] . ' UTC') - strtotime($this->clock->now() . ' UTC');
        return max(0, (int) $left);
    }

    public function addDemand(string $kind, string $reason): int
    {
        return (int) $this->locked($kind, function (array $row, string $now) use ($kind, $reason): int {
            $seq = $this->maxDemandSeq($kind) + 1;
            $this->db->table(self::DEMAND)->insert(
                ['kind' => $kind, 'seq' => $seq, 'reason' => $reason, 'created_at' => $now],
            );
            return $seq;
        });
    }

    public function maxDemandSeq(string $kind): int
    {
        return (int) ($this->db->table(self::DEMAND)->where('kind', '=', $kind)->max('seq') ?? 0);
    }

    /**
     * Record what one target did with one journal entry (fenced). A `pending` acknowledgement keeps
     * its task ids until they finish: a retry may not replace them while they could still run.
     *
     * @param list<int> $taskUids
     */
    public function recordAck(Fence $fence, int $entrySeq, string $targetKey, array $taskUids, string $status): void
    {
        $write = function (array $row, string $now) use ($fence, $entrySeq, $targetKey, $taskUids, $status): void {
            $uids = implode(',', array_map('intval', $taskUids));
            $existing = $this->db->table(self::ACKS)->where('kind', '=', $fence->kind)
                ->where('entry_seq', '=', $entrySeq)->where('target', '=', $targetKey)->first();
            if ($existing === null) {
                $this->db->table(self::ACKS)->insert([
                    'kind' => $fence->kind, 'entry_seq' => $entrySeq, 'target' => $targetKey, 'task_uid' => $uids,
                    'status' => $status, 'writer_token' => $fence->writer(), 'updated_at' => $now,
                ]);
                return;
            }
            $stored = (string) $existing['task_uid'];
            // Pending tasks may still run: a write may narrow them to those still outstanding, or
            // record their outcome, but never replace them with a retry's tasks.
            $narrowing = $uids === '' ? false : array_diff(explode(',', $uids), explode(',', $stored)) === [];
            if ($existing['status'] === 'pending' && $stored !== '' && $stored !== $uids && !$narrowing) {
                throw new \LogicException(
                    "Entry {$entrySeq} still has pending tasks on {$targetKey}; confirm them first.",
                );
            }
            $this->db->table(self::ACKS)->where('kind', '=', $fence->kind)->where('entry_seq', '=', $entrySeq)
                ->where('target', '=', $targetKey)
                ->update([
                    'task_uid' => $uids, 'status' => $status, 'writer_token' => $fence->writer(), 'updated_at' => $now,
                ]);
        };
        $this->fenced($fence, $write);
    }

    /** @return array{status: string, task_uids: list<int>}|null */
    public function ack(string $kind, int $entrySeq, string $targetKey): ?array
    {
        $row = $this->db->table(self::ACKS)->where('kind', '=', $kind)->where('entry_seq', '=', $entrySeq)
            ->where('target', '=', $targetKey)->first();
        if ($row === null) {
            return null;
        }
        $uids = (string) $row['task_uid'] === '' ? [] : array_map('intval', explode(',', (string) $row['task_uid']));
        return ['status' => (string) $row['status'], 'task_uids' => $uids];
    }

    /**
     * @param list<int> $seqs
     * @return list<int> the seqs with a succeeded acknowledgement for that target
     */
    public function ackedFor(string $kind, string $targetKey, array $seqs): array
    {
        if ($seqs === []) {
            return [];
        }
        $rows = $this->db->table(self::ACKS)->select(['entry_seq'])->where('kind', '=', $kind)
            ->where('target', '=', $targetKey)->where('status', '=', 'succeeded')->whereIn('entry_seq', $seqs)->get();
        $acked = array_map(static fn (array $r): int => (int) $r['entry_seq'], $rows);
        sort($acked);
        return $acked;
    }

    /**
     * Unresolved entries, plus — when `$afterSeq` is given — every entry after it, resolved or not
     * (a rebuild replays everything journaled since it began).
     *
     * @return list<JournalEntry>
     */
    public function unresolvedEntries(string $kind, ?int $afterSeq = null): array
    {
        $rows = $this->db->table(self::CHANGES)->where('kind', '=', $kind)->orderBy('seq', 'ASC')->get();
        $entries = [];
        foreach ($rows as $row) {
            $resolved = (int) $row['resolved'] === 1;
            if (!$resolved || ($afterSeq !== null && (int) $row['seq'] > $afterSeq)) {
                $entries[] = new JournalEntry($kind, (string) $row['source_id'], (int) $row['seq'], $resolved);
            }
        }
        return $entries;
    }

    /**
     * Resolve an entry only if every current target has a succeeded acknowledgement for it — the
     * targets read under the same lock as the resolution.
     */
    public function resolveIfComplete(Fence $drainer, int $seq): bool
    {
        return (bool) $this->fenced($drainer, function (array $row) use ($drainer, $seq): bool {
            foreach (self::currentTargetKeys($row) as $key) {
                if ($this->ackedFor($drainer->kind, $key, [$seq]) === []) {
                    return false;
                }
            }
            $this->db->table(self::CHANGES)->where('kind', '=', $drainer->kind)->where('seq', '=', $seq)
                ->update(['resolved' => 1, 'failed_at' => null, 'error' => null]);
            return true;
        });
    }

    /** Record why an entry failed; it stays unresolved and is retried. */
    public function markEntryFailed(string $kind, int $seq, string $error): void
    {
        $this->db->table(self::CHANGES)->where('kind', '=', $kind)->where('seq', '=', $seq)
            ->update(['failed_at' => $this->clock->now(), 'error' => ErrorText::sanitize($error)]);
    }

    public function setStatus(Fence $fence, string $status, ?string $error = null): void
    {
        $this->fenced($fence, function (array $row, string $now) use ($fence, $status, $error): void {
            $text = $error === null ? null : ErrorText::sanitize($error);
            $this->update($fence->kind, ['status' => $status, 'last_error' => $text, 'updated_at' => $now]);
        });
    }

    /**
     * A live update failed. Unfenced: it comes from the request path. A kind mid-build stays
     * `building` (promotion then lands on `out_of_date`); otherwise it becomes `out_of_date`.
     */
    public function markOutOfDate(string $kind, string $error): void
    {
        $this->locked($kind, function (array $row, string $now) use ($kind, $error): void {
            $status = $row['status'] === 'building' ? 'building' : 'out_of_date';
            $this->update(
                $kind,
                ['status' => $status, 'last_error' => ErrorText::sanitize($error), 'updated_at' => $now],
            );
        });
    }

    /**
     * The acknowledgement keys of the targets a live change must reach: the active target, and the
     * claimed build target while one exists.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function currentTargetKeys(array $row): array
    {
        $keys = [];
        if ($row['active_target'] !== null && $row['active_target'] !== '') {
            $keys[] = Target::keyFor((string) $row['active_target'], (int) $row['generation']);
        }
        $building = $row['building_target'];
        if ($building !== null && $building !== '' && $row['building_generation'] !== null) {
            $keys[] = Target::keyFor((string) $row['building_target'], (int) $row['building_generation']);
        }
        return $keys;
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function lock(string $kind): array
    {
        $locked = $this->db->table(self::STATE)->where('kind', '=', $kind)
            ->update(['locked_at' => gmdate('Y-m-d H:i:s')]);
        if ($locked !== 1) {
            throw new StaleFence("No search index state for '{$kind}'.");
        }
        $now = $this->clock->now();
        return [(array) $this->row($kind), $now];
    }

    /** @param array<string, mixed> $row */
    private function holds(Fence $fence, array $row, string $now): bool
    {
        return match ($fence->role) {
            Fence::BUILDER => $row['owner_token'] === $fence->token
                && (int) $row['building_generation'] === $fence->generation
                && (string) $row['lease_until'] > $now,
            Fence::DRAINER => $row['drainer_token'] === $fence->token && (string) $row['drainer_lease_until'] > $now,
            Fence::PROMOTED => (int) $row['generation'] === $fence->generation,
            default => false,
        };
    }

    /** @param array<string, mixed> $values */
    private function update(string $kind, array $values): void
    {
        $this->db->table(self::STATE)->where('kind', '=', $kind)->update($values);
    }

    private static function plus(string $time, int $seconds): string
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('UTC')))
            ->modify("+{$seconds} seconds")
            ->format('Y-m-d H:i:s');
    }
}
