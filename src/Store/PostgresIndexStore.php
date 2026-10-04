<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

use Glueful\Database\Connection;
use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\SearchDocument;
use Thallo\Search\Identity\DocumentId;
use Thallo\Search\Lifecycle\Fence;
use Thallo\Search\Lifecycle\StateRepository;

/**
 * The Postgres index store (search block spec §3.3, §3.5.5): every kind and every generation in the
 * one `search_documents` table, owned by the workspace. Each write runs inside the state row's lock
 * and is checked against the writer's fence there, so a stale writer's transaction rolls back and
 * nothing it sent can land later. A row's generation only ever rises: a live write that read an
 * older target can never push a row the build already stamped below the sweep line.
 *
 * Postgres has one physical row per document for every generation, so one replacement satisfies
 * every target generation at once, and its receipts name no tasks: committed is done.
 */
final class PostgresIndexStore implements IndexStore
{
    private const TABLE = 'search_documents';

    public function __construct(
        private readonly Connection $db,
        private readonly StateRepository $state,
    ) {
    }

    public function write(Target $target, array $documents, Fence $fence): array
    {
        $this->state->fenced($fence, function () use ($target, $documents): void {
            foreach ($documents as $document) {
                $this->upsert($document, $target->generation);
            }
        });
        return [new TargetReceipt($target->key(), [])];
    }

    public function replaceSource(array $targets, string $kind, string $sourceId, array $documents, Fence $fence): array
    {
        $generation = 0;
        foreach ($targets as $target) {
            $generation = max($generation, $target->generation);
        }
        $this->state->fenced($fence, function () use ($kind, $sourceId, $documents, $generation): void {
            $keep = array_map(static fn (SearchDocument $d): string => $d->locale, $documents);
            $stale = $this->db->table(self::TABLE)->where('kind', '=', $kind)->where('source_id', '=', $sourceId);
            if ($keep !== []) {
                $stale->whereRaw(
                    'locale NOT IN (' . implode(', ', array_fill(0, count($keep), '?')) . ')',
                    array_values($keep),
                );
            }
            $stale->delete();
            foreach ($documents as $document) {
                $this->upsert($document, $generation);
            }
        });
        return array_map(static fn (Target $t): TargetReceipt => new TargetReceipt($t->key(), []), $targets);
    }

    public function sweep(string $kind, int $belowGeneration, Fence $fence): void
    {
        $this->state->fenced($fence, function () use ($kind, $belowGeneration): void {
            $this->db->table(self::TABLE)->where('kind', '=', $kind)->where(
                'generation',
                '<',
                $belowGeneration,
            )->delete();
        });
    }

    public function confirm(TargetReceipt $receipt): ReceiptOutcome
    {
        return ReceiptOutcome::succeeded();
    }

    public function search(array $targets, StoreQuery $query): StoreResult
    {
        $words = PostgresTextSearch::words($query->q);
        if ($words === []) {
            return new StoreResult([], 0);
        }
        [$kindClause, $kindBindings] = self::kindClause($query->kinds);
        if ($kindClause === null) {
            return new StoreResult([], 0);
        }

        $config = PostgresTextSearch::configFor($query->locale);
        // Per word, its stem in the request's language OR the word as a prefix; all words AND-ed.
        $perWord = "(plainto_tsquery(?::regconfig, ?) || to_tsquery('simple', ?))";
        $tsquery = '(' . implode(' && ', array_fill(0, count($words), $perWord)) . ')';
        $terms = [];
        foreach ($words as $word) {
            array_push($terms, $config, $word, mb_strlen($word) > 1 ? $word . ':*' : $word);
        }

        $scoped = fn () => $this->db->table(self::TABLE)
            ->whereIn('locale', [$query->locale, '*'])
            ->whereRaw('tsv @@ ' . $tsquery, $terms)
            ->whereRaw($kindClause, $kindBindings);

        $total = $scoped()->count();
        if ($total === 0) {
            return new StoreResult([], 0);
        }
        $rows = $scoped()
            ->select(['kind', 'source_id', 'entry_uuid', 'locale', 'subtype', 'content_type_uuid'])
            ->selectRaw("ts_rank_cd(tsv, {$tsquery}, 32) AS score", $terms)
            ->orderByRaw('score DESC, id ASC')
            ->limit($query->limit)
            ->offset($query->offset)
            ->get();

        $hits = [];
        foreach ($rows as $row) {
            $hits[] = new StoreHit(
                (string) ($row['kind'] ?? 'entries'),
                (string) ($row['source_id'] ?? $row['entry_uuid']),
                (string) $row['locale'],
                isset($row['subtype']) ? (string) $row['subtype']
                    : (isset($row['content_type_uuid']) ? (string) $row['content_type_uuid'] : null),
                (float) ($row['score'] ?? 0.0),
            );
        }
        return new StoreResult($hits, $total);
    }

    public function createTarget(Target $target): void
    {
        // One table holds every generation; the migration created it.
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
        $ready = $this->db->getDriverName() === 'pgsql' && $this->db->getSchemaBuilder()->hasColumn(self::TABLE, 'tsv');
        return new Readiness($ready, $ready ? null : 'The Postgres search index is not set up. Run the migrations.');
    }

    private function upsert(SearchDocument $document, int $generation): void
    {
        $id = DocumentId::encode($document->kind, $document->sourceId, $document->locale);
        $existing = $this->db->table(self::TABLE)->select(['generation'])->where('doc_id', '=', $id)->first();
        $generation = max($generation, (int) ($existing['generation'] ?? 0));
        $this->db->table(self::TABLE)->where('doc_id', '=', $id)->delete();
        $this->db->table(self::TABLE)->insert([
            'doc_id' => $id,
            'kind' => $document->kind,
            'source_id' => $document->sourceId,
            'locale' => $document->locale,
            'subtype' => $document->subtype,
            'href' => $document->href,
            'title' => $document->title,
            'body' => $document->body,
            'meta' => $document->meta === [] ? null : json_encode($document->meta, JSON_THROW_ON_ERROR),
            'ts_config' => PostgresTextSearch::configFor($document->locale === '*' ? 'simple' : $document->locale),
            'generation' => $generation,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * `(kind = ?) OR (kind = ? AND subtype IN (...))`, one disjunct per kind an audience may match.
     *
     * @param array<string, KindFilter> $kinds
     * @return array{0: ?string, 1: list<string>}
     */
    private static function kindClause(array $kinds): array
    {
        $parts = [];
        $bindings = [];
        foreach ($kinds as $kind => $filter) {
            if ($filter->mode === KindFilter::NONE) {
                continue;
            }
            if ($filter->mode === KindFilter::ALL) {
                $parts[] = '(kind = ?)';
                $bindings[] = $kind;
                continue;
            }
            $parts[] = '(kind = ? AND subtype IN (' . implode(
                ', ',
                array_fill(0, count($filter->subtypes), '?'),
            ) . '))';
            array_push($bindings, $kind, ...$filter->subtypes);
        }
        return $parts === [] ? [null, []] : ['(' . implode(' OR ', $parts) . ')', $bindings];
    }
}
