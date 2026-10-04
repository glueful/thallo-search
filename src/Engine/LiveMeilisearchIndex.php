<?php

declare(strict_types=1);

namespace Thallo\Search\Engine;

use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Contracts\MultiSearchFederation;
use Meilisearch\Exceptions\ApiException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The only class in this pack that imports Glueful\Extensions\Meilisearch\* (and the Meilisearch
 * client beneath it). Names arrive unprefixed and leave prefixed by the extension's configured
 * prefix; document writes return their task uid and never wait.
 */
final class LiveMeilisearchIndex implements MeilisearchIndex
{
    public function __construct(private readonly IndexManager $manager)
    {
    }

    public static function fromContainer(ContainerInterface $container): self
    {
        return new self($container->get(IndexManager::class));
    }

    public function serverVersion(): string
    {
        return (string) ($this->manager->getClient()->version()['pkgVersion'] ?? '0.0.0');
    }

    public function ensureIndex(string $uid, array $settings): void
    {
        $this->manager->getOrCreateIndex($uid);
        $this->manager->updateSettings($uid, $settings);
    }

    public function addDocuments(string $uid, array $documents): int
    {
        return (int) $this->index($uid)->addDocuments($documents, 'id')['taskUid'];
    }

    public function deleteDocuments(string $uid, array $ids): int
    {
        return (int) $this->index($uid)->deleteDocuments($ids)['taskUid'];
    }

    public function deleteByFilter(string $uid, string $filter): int
    {
        return (int) $this->index($uid)->deleteDocuments(['filter' => $filter])['taskUid'];
    }

    public function deleteIndex(string $uid): int
    {
        return (int) $this->client()->deleteIndex($this->prefixed($uid))['taskUid'];
    }

    public function task(int $taskUid): array
    {
        $task = $this->client()->getTask($taskUid);
        return [
            'status' => (string) ($task['status'] ?? 'enqueued'),
            'error' => isset($task['error']['message']) ? (string) $task['error']['message'] : null,
        ];
    }

    public function listIndexes(string $prefix): array
    {
        $full = $this->prefixed($prefix);
        $strip = strlen($this->prefixed(''));
        $names = [];
        foreach ($this->client()->getIndexes((new IndexesQuery())->setLimit(1000))->getResults() as $index) {
            $uid = $index->getUid();
            if (is_string($uid) && str_starts_with($uid, $full)) {
                $names[] = substr($uid, $strip);
            }
        }
        return $names;
    }

    public function federatedSearch(array $queries, int $limit, int $offset): array
    {
        $prefixed = array_map(fn (array $q): array => [
            'indexUid' => $this->prefixed($q['indexUid']),
            'q' => $q['q'],
            'filter' => $q['filter'],
            'showRankingScore' => true,
        ], $queries);
        try {
            $raw = $this->client()->multiSearch(
                $prefixed,
                (new MultiSearchFederation())->setLimit($limit)->setOffset($offset),
            );
        } catch (ApiException $e) {
            throw $this->notFound($e, $queries) ?? $e;
        }
        $strip = strlen($this->prefixed(''));
        $hits = [];
        foreach ((array) ($raw['hits'] ?? []) as $hit) {
            $federation = (array) ($hit['_federation'] ?? []);
            $hit['_index'] = substr((string) ($federation['indexUid'] ?? ''), $strip);
            $hit['_score'] = (float) ($federation['weightedRankingScore'] ?? $hit['_rankingScore'] ?? 0.0);
            $hits[] = $hit;
        }
        return ['hits' => $hits, 'estimatedTotalHits' => (int) ($raw['estimatedTotalHits'] ?? count($hits))];
    }

    public function rawSearch(string $uid, string $query, array $params): array
    {
        try {
            return $this->index($uid)->rawSearch($query, $params);
        } catch (ApiException $e) {
            throw $this->notFound($e, [['indexUid' => $uid]]) ?? $e;
        }
    }

    public function reachable(string $uid): bool
    {
        try {
            $this->manager->getStats($uid);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function index(string $uid): \Meilisearch\Endpoints\Indexes
    {
        return $this->client()->index($this->prefixed($uid));
    }

    private function client(): \Glueful\Extensions\Meilisearch\Client\MeilisearchClient
    {
        return $this->manager->getClient();
    }

    private function prefixed(string $uid): string
    {
        return $this->client()->prefixedIndexName($uid);
    }

    /** @param list<array{indexUid: string}> $queries */
    private function notFound(ApiException $e, array $queries): ?IndexNotFound
    {
        if ($e->errorCode !== 'index_not_found') {
            return null;
        }
        foreach ($queries as $query) {
            if (str_contains((string) $e->getMessage(), $this->prefixed($query['indexUid']))) {
                return new IndexNotFound($query['indexUid']);
            }
        }
        return new IndexNotFound($queries[0]['indexUid'] ?? '');
    }
}
