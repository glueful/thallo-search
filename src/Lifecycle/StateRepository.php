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

    /** @return array<string, mixed> the kind's row, created (pending) if missing */
    public function ensure(string $kind): array
    {
        $row = $this->row($kind);
        if ($row !== null) {
            return $row;
        }
        try {
            // In its own (nested) transaction: inside a caller's transaction a failed insert rolls
            // back to this savepoint only, so the re-read below still works on Postgres.
            $this->db->transaction(function () use ($kind): void {
                $this->db->table(self::STATE)->insert([
                    'kind' => $kind, 'status' => 'pending', 'updated_at' => $this->clock->now(),
                ]);
            });
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
        $entries = [];
        foreach ($this->relevantChanges($kind, $afterSeq) as $row) {
            $entries[] = new JournalEntry(
                $kind,
                (string) $row['source_id'],
                (int) $row['seq'],
                (int) $row['resolved'] === 1,
            );
        }
        return $entries;
    }

    /**
     * The journal rows a target still needs: every unresolved one, and with `$afterSeq` every one
     * after it — read by the index, never the whole history. In seq order.
     *
     * @return list<array<string, mixed>>
     */
    private function relevantChanges(string $kind, ?int $afterSeq, ?int $upToSeq = null): array
    {
        $open = $this->db->table(self::CHANGES)->where('kind', '=', $kind)->where('resolved', '=', 0);
        if ($upToSeq !== null) {
            $open->where('seq', '<=', $upToSeq);
        }
        $rows = $open->get();
        if ($afterSeq !== null) {
            $later = $this->db->table(self::CHANGES)->where('kind', '=', $kind)->where('resolved', '=', 1)
                ->where('seq', '>', $afterSeq);
            if ($upToSeq !== null) {
                $later->where('seq', '<=', $upToSeq);
            }
            $rows = array_merge($rows, $later->get());
        }
        usort($rows, static fn (array $a, array $b): int => (int) $a['seq'] <=> (int) $b['seq']);
        return $rows;
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

    /**
     * Promote the build target — under the row lock journal appends also take — once every relevant
     * entry (unresolved, or journaled since the build began, up to the journal head read here) has a
     * succeeded acknowledgement for it. Returns the seqs still missing one, or null once promoted.
     * Promoted entries resolve: the build target is now the only one a change must reach.
     *
     * @return list<int>|null
     */
    public function promote(Fence $builder, Target $target, int $journalStartSeq): ?array
    {
        return $this->fenced($builder, function (
            array $row,
            string $now
        ) use (
            $builder,
            $target,
            $journalStartSeq,
        ): ?array {
            $head = (int) $row['journal_head'];
            $relevant = array_map(
                static fn (array $change): int => (int) $change['seq'],
                $this->relevantChanges($builder->kind, $journalStartSeq, $head),
            );
            $missing = array_values(array_diff($relevant, $this->ackedFor($builder->kind, $target->key(), $relevant)));
            if ($missing !== []) {
                return $missing;
            }
            if ($relevant !== []) {
                $this->db->table(self::CHANGES)->where('kind', '=', $builder->kind)->whereIn('seq', $relevant)
                    ->update(['resolved' => 1, 'failed_at' => null, 'error' => null]);
            }
            // Resolved history at or below this build's start can never be relevant again: the next
            // build starts at a later head. Pruned with its acknowledgements, so the journal stays
            // bounded by the changes since the last promotion.
            $this->db->table(self::CHANGES)->where('kind', '=', $builder->kind)->where('resolved', '=', 1)
                ->where('seq', '<=', $journalStartSeq)->delete();
            $this->db->table(self::ACKS)->where('kind', '=', $builder->kind)
                ->where('entry_seq', '<=', $journalStartSeq)->delete();
            $failed = $this->db->table(self::CHANGES)->where('kind', '=', $builder->kind)->where('resolved', '=', 0)
                ->whereNotNull('failed_at')->count();
            $this->update($builder->kind, [
                'generation' => $builder->generation,
                'active_target' => $target->name,
                'building_generation' => null,
                'building_target' => null,
                'owner_token' => null,
                'lease_until' => null,
                'cursor' => null,
                'status' => $failed > 0 ? 'out_of_date' : 'ready',
                'last_error' => $failed > 0 ? $row['last_error'] : null,
                'last_success_at' => $now,
                'documents' => (int) $row['processed'],
                'updated_at' => $now,
            ]);
            return null;
        });
    }

    /** Give up a build (fenced): its claim and build target go, with the status and reason. */
    public function abandonBuild(Fence $builder, string $status, string $error): void
    {
        $this->fenced($builder, function (array $row, string $now) use ($builder, $status, $error): void {
            $this->update($builder->kind, [
                'owner_token' => null, 'lease_until' => null, 'building_generation' => null,
                'building_target' => null, 'cursor' => null,
                'status' => $status, 'last_error' => ErrorText::sanitize($error), 'updated_at' => $now,
            ]);
        });
    }

    /**
     * @param list<int> $seqs
     * @return list<JournalEntry>
     */
    public function entries(string $kind, array $seqs): array
    {
        if ($seqs === []) {
            return [];
        }
        $rows = $this->db->table(self::CHANGES)->where(
            'kind',
            '=',
            $kind,
        )->whereIn('seq', $seqs)->orderBy('seq', 'ASC')->get();
        return array_map(
            static fn (array $r): JournalEntry => new JournalEntry(
                $kind,
                (string) $r['source_id'],
                (int) $r['seq'],
                (int) $r['resolved'] === 1,
            ),
            $rows,
        );
    }

    public function now(): string
    {
        return $this->clock->now();
    }

    /** @return list<array{name: string, retired_at: string}> */
    public function retiredTargets(string $kind): array
    {
        $raw = $this->row($kind)['retired_targets'] ?? null;
        $list = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($list) ? array_values($list) : [];
    }

    public function addRetired(string $kind, string $name): void
    {
        $this->locked($kind, function (array $row, string $now) use ($kind, $name): void {
            $list = $this->retiredTargets($kind);
            $list[] = ['name' => $name, 'retired_at' => $now];
            $this->update($kind, ['retired_targets' => json_encode($list, JSON_THROW_ON_ERROR)]);
        });
    }

    /** @param list<string> $names the retired targets still within their grace period */
    public function keepRetired(string $kind, array $names): void
    {
        $this->locked($kind, function () use ($kind, $names): void {
            $list = array_values(array_filter(
                $this->retiredTargets($kind),
                static fn (array $r): bool => in_array($r['name'], $names, true),
            ));
            $this->update($kind, ['retired_targets' => json_encode($list, JSON_THROW_ON_ERROR)]);
        });
    }

    /** When the oldest demand not yet satisfied was recorded, or null if none is outstanding. */
    public function oldestUnsatisfiedDemandAt(string $kind): ?string
    {
        $satisfied = (int) ($this->row($kind)['satisfied_seq'] ?? 0);
        $row = $this->db->table(self::DEMAND)->where('kind', '=', $kind)->where('seq', '>', $satisfied)
            ->orderBy('seq', 'ASC')->first();
        return $row === null ? null : (string) $row['created_at'];
    }

    /** A wake-up could not be queued: say so, without touching the status a build owns. */
    public function noteQueueFailure(string $kind, string $error): void
    {
        $this->ensure($kind);
        $this->update(
            $kind,
            ['last_error' => 'queue: ' . ErrorText::sanitize($error), 'updated_at' => $this->clock->now()],
        );
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
