<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Glueful\Database\Connection;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Search\Sources\EntriesContributor;
use Thallo\Search\Store\IndexStore;
use Thallo\Search\Store\Target;

/**
 * Moves a workspace from the legacy index to the v2 one (search block spec §3.5.6). Until the
 * entries kind is built and ready in v2, queries keep reading the old documents (where that is
 * safe); then, in one update under the entries row lock, every kind of the workspace moves to v2.
 * Postgres legacy rows are owned per workspace and are deleted right after; the legacy Meilisearch
 * index is shared, so it is retired only when no workspace still depends on it.
 */
final class Cutover
{
    public const LEGACY_FLAG = 'search.legacy_index';

    public function __construct(
        private readonly StateRepository $state,
        private readonly IndexStore $store,
        private readonly SearchIndexLocator $locator,
        private readonly Workspace $workspace,
        private readonly SystemChannel $flags,
        private readonly Connection $db,
        private readonly string $legacyUid,
    ) {
    }

    /** In the current workspace: flip to v2 once entries are ready, then drop its legacy rows. */
    public function flipIfReady(): void
    {
        $flipped = $this->state->flipToV2IfEntriesReady(EntriesContributor::KIND);
        $row = $this->state->row(EntriesContributor::KIND);
        $movedToV2 = $flipped || ($row['format'] ?? 'legacy') === 'v2';
        if ($movedToV2 && $this->locator->engine() === SearchIndexLocator::POSTGRES) {
            // Idempotent, retried on every reconcile until it succeeds.
            $this->db->table('search_documents')->whereNull('kind')->delete();
        }
    }

    /**
     * Installation-wide: delete the shared legacy Meilisearch index once every workspace has moved
     * off it. A failure leaves the flag unset, so the next reconcile tries again.
     */
    public function retireLegacyIndexIfUnused(): void
    {
        if ($this->locator->engine() !== SearchIndexLocator::MEILISEARCH) {
            return;
        }
        if (method_exists($this->flags, 'clearCache')) {
            $this->flags->clearCache();
        }
        if ($this->flags->get(self::LEGACY_FLAG) === 'retired') {
            return;
        }
        $everyWorkspaceMoved = true;
        $this->workspace->each(function () use (&$everyWorkspaceMoved): void {
            if (($this->state->row(EntriesContributor::KIND)['format'] ?? 'legacy') !== 'v2') {
                $everyWorkspaceMoved = false;
            }
        });
        if (!$everyWorkspaceMoved) {
            return;
        }
        $this->store->dropTarget(new Target(SearchIndexLocator::MEILISEARCH, $this->legacyUid, 0));
        $this->flags->put(self::LEGACY_FLAG, 'retired');
    }
}
