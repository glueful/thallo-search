<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\SearchDocument;
use Thallo\Search\Engine\MeilisearchIndex;
use Thallo\Search\Identity\DocumentId;
use Thallo\Search\Lifecycle\Fence;

/**
 * The Meilisearch index store (search block spec §3.3, §3.5.5). Each build attempt writes into its
 * own index, which no query reads until a fenced database update promotes it, so a stale builder's
 * late write lands somewhere harmless; live writes are guarded by the drainer's lease before they
 * are sent. Writes return their tasks and never wait — accepted is not completed — and
 * {@see confirm()} reports the real outcome: a task still running after the bounded wait is
 * pending, never failed, and keeps the receipt pending even if a sibling task already failed.
 */
final class MeilisearchIndexStore implements IndexStore
{
    public const MIN_VERSION = '1.10.0';
    private const SEARCHABLE = ['title', 'body'];
    private const FILTERABLE = ['kind', 'source_id', 'subtype', 'locale'];

    public function __construct(
        private readonly MeilisearchIndex $index,
        private readonly int $taskTimeoutMs = 10_000,
        private readonly int $pollMs = 50,
    ) {
    }

    public function write(Target $target, array $documents, Fence $fence): array
    {
        if ($documents === []) {
            return [new TargetReceipt($target->key(), [])];
        }
        $task = $this->index->addDocuments($target->name, array_map(self::document(...), $documents));
        return [new TargetReceipt($target->key(), [$task])];
    }

    public function replaceSource(array $targets, string $kind, string $sourceId, array $documents, Fence $fence): array
    {
        $locales = array_map(static fn (SearchDocument $d): string => self::quote($d->locale), $documents);
        $filter = 'kind = ' . self::quote($kind) . ' AND source_id = ' . self::quote($sourceId)
            . ($locales === [] ? '' : ' AND locale NOT IN [' . implode(', ', $locales) . ']');
        $receipts = [];
        foreach ($targets as $target) {
            $tasks = [];
            if ($documents !== []) {
                $tasks[] = $this->index->addDocuments($target->name, array_map(self::document(...), $documents));
            }
            // Enqueued after the upsert, so it never removes the locales just written.
            $tasks[] = $this->index->deleteByFilter($target->name, $filter);
            $receipts[] = new TargetReceipt($target->key(), $tasks);
        }
        return $receipts;
    }

    public function sweep(string $kind, int $belowGeneration, Fence $fence): void
    {
        // Each generation is its own index; retirement removes the old ones.
    }

    public function confirm(TargetReceipt $receipt): ReceiptOutcome
    {
        if ($receipt->taskUids === []) {
            return ReceiptOutcome::succeeded();
        }
        $deadline = microtime(true) + $this->taskTimeoutMs / 1000;
        do {
            $outstanding = [];
            $failed = false;
            foreach ($receipt->taskUids as $uid) {
                $status = $this->index->task($uid)['status'];
                if ($status === 'enqueued' || $status === 'processing') {
                    $outstanding[] = $uid;
                } elseif ($status !== 'succeeded') {
                    $failed = true;
                }
            }
            if ($outstanding === []) {
                return new ReceiptOutcome($failed ? ConfirmResult::FAILED : ConfirmResult::SUCCEEDED);
            }
            if (microtime(true) >= $deadline) {
                return new ReceiptOutcome(ConfirmResult::PENDING, $outstanding);
            }
            usleep($this->pollMs * 1000);
        } while (true);
    }

    /** @param array<string, Target> $targets the active target per kind */
    public function search(array $targets, StoreQuery $query): StoreResult
    {
        $queries = [];
        foreach ($query->kinds as $kind => $filter) {
            $target = $targets[$kind] ?? null;
            if ($target === null || $filter->mode === KindFilter::NONE) {
                continue;
            }
            $queries[] = [
                'indexUid' => $target->name,
                'q' => $query->q,
                'filter' => self::filter($kind, $filter, $query->locale),
            ];
        }
        if ($queries === []) {
            return new StoreResult([], 0);
        }
        $raw = $this->index->federatedSearch($queries, $query->limit, $query->offset);
        $hits = [];
        foreach ($raw['hits'] as $hit) {
            $hits[] = new StoreHit(
                (string) $hit['kind'],
                (string) $hit['source_id'],
                (string) $hit['locale'],
                isset($hit['subtype']) ? (string) $hit['subtype'] : null,
                (float) ($hit['_score'] ?? 0.0),
            );
        }
        return new StoreResult($hits, $raw['estimatedTotalHits']);
    }


    public function createTarget(Target $target): void
    {
        $this->index->ensureIndex($target->name, [
            'searchableAttributes' => self::SEARCHABLE,
            'filterableAttributes' => self::FILTERABLE,
        ]);
    }

    public function dropTarget(Target $target): void
    {
        $this->index->deleteIndex($target->name);
    }

    public function listTargets(string $prefix): array
    {
        return $this->index->listIndexes($prefix);
    }

    public function readiness(): Readiness
    {
        try {
            $version = $this->index->serverVersion();
        } catch (\Throwable) {
            return new Readiness(
                false,
                'Meilisearch cannot be reached. Check MEILISEARCH_HOST, or set `SEARCH_ENGINE=postgres`.',
            );
        }
        if (version_compare($version, self::MIN_VERSION, '<')) {
            return new Readiness(
                false,
                "Meilisearch 1.10 or newer is required for search across kinds (the server reports {$version}). "
                . 'Upgrade the server, or set `SEARCH_ENGINE=postgres`.',
                $version,
            );
        }
        return new Readiness(true, null, $version);
    }

    /** @return array<string, mixed> */
    private static function document(SearchDocument $document): array
    {
        return [
            'id' => DocumentId::encode($document->kind, $document->sourceId, $document->locale),
            'kind' => $document->kind,
            'source_id' => $document->sourceId,
            'locale' => $document->locale,
            'subtype' => $document->subtype,
            'href' => $document->href,
            'title' => $document->title,
            'body' => $document->body,
            'meta' => $document->meta,
        ];
    }

    private static function filter(string $kind, KindFilter $filter, string $locale): string
    {
        $clauses = ['kind = ' . self::quote($kind)];
        if ($filter->mode === KindFilter::SUBTYPES) {
            $clauses[] = 'subtype IN [' . implode(', ', array_map(self::quote(...), $filter->subtypes)) . ']';
        }
        $clauses[] = '(locale = ' . self::quote($locale) . ' OR locale = "*")';
        return implode(' AND ', $clauses);
    }

    private static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
