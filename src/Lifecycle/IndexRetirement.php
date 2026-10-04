<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Search\Store\IndexStore;
use Thallo\Search\Store\Target;

/**
 * Removes Meilisearch indexes no query should read any more (search block spec §3.5.8). An index
 * that stops being active is first retired with a time and dropped only after a grace period longer
 * than a query may take, so a query that read its name just before promotion still finds it. Each
 * collection also drops orphans for the workspace and kind — indexes neither active, nor claimed by
 * a live build, nor within their grace — including an abandoned attempt's index that a late write
 * recreated.
 */
final class IndexRetirement
{
    public function __construct(
        private readonly StateRepository $state,
        private readonly IndexStore $store,
        private readonly SearchIndexLocator $locator,
        private readonly SearchSourceRegistry $sources,
        private readonly int $graceSeconds,
    ) {
    }

    public function retire(string $kind, Target $target): void
    {
        $this->state->addRetired($kind, $target->name);
    }

    public function collect(string $kind): void
    {
        if ($this->locator->engine() !== SearchIndexLocator::MEILISEARCH) {
            return;
        }
        $row = $this->state->row($kind);
        if ($row === null) {
            return;
        }
        $now = $this->state->now();
        $kept = [];
        foreach ($this->state->retiredTargets($kind) as $retired) {
            $age = strtotime($now . ' UTC') - strtotime($retired['retired_at'] . ' UTC');
            if ($age > $this->graceSeconds) {
                $this->store->dropTarget(new Target(SearchIndexLocator::MEILISEARCH, $retired['name'], 0));
            } else {
                $kept[] = $retired['name'];
            }
        }
        $this->state->keepRetired($kind, $kept);

        // List first, then read the row: a build claims before it creates its index, so any build
        // index the listing saw has a claim this fresh read sees.
        $names = $this->store->listTargets($this->locator->targetPrefix($kind));
        $row = $this->state->row($kind) ?? $row;
        $claimLive = $row['owner_token'] !== null && (string) $row['lease_until'] > $this->state->now();
        if ($claimLive) {
            return; // a build is under way: its index may exist before it is recorded
        }
        $protected = array_filter([
            (string) ($row['active_target'] ?? ''),
            (string) ($row['building_target'] ?? ''),
            ...$kept,
        ]);
        foreach ($names as $name) {
            if (!in_array($name, $protected, true)) {
                $this->store->dropTarget(new Target(SearchIndexLocator::MEILISEARCH, $name, 0));
            }
        }
    }

    /**
     * Delete the single shared index an older install kept on Meilisearch (spec §3.5.6, amended):
     * nothing reads it now. True when it is gone (or never existed), false on Postgres.
     */
    public function dropOldSharedIndex(): bool
    {
        if ($this->locator->engine() !== SearchIndexLocator::MEILISEARCH) {
            return false;
        }
        $name = $this->locator->indexName();
        if (in_array($name, $this->store->listTargets($name), true)) {
            $this->store->dropTarget(new Target(SearchIndexLocator::MEILISEARCH, $name, 0));
        }
        return true;
    }

    public function collectAll(): void
    {
        foreach (array_keys($this->sources->all()) as $kind) {
            $this->collect($kind);
        }
    }
}
