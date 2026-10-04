<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Contracts\Settings\SystemChannel;

/**
 * When a kind needs rebuilding (search block spec §3.5.7). `relevantVersion` is the newest
 * capability state version at which search, or anything the kind requires, was switched — read
 * afresh, so an off/on cycle nobody observed still reads as a change.
 */
final class DemandResolver
{
    public function __construct(
        private readonly SearchSourceRegistry $sources,
        private readonly SystemChannel $flags,
        private readonly ?StateRepository $state = null,
        /** The engine answering now; an active index the other engine built needs rebuilding. */
        private readonly ?SearchIndexLocator $locator = null,
    ) {
    }

    /**
     * Why a kind needs rebuilding in the current workspace, or null: no state row yet (a new
     * workspace), an active index the other engine built (the engine was switched), a capability
     * switched since the last successful build, a changed document shape, or recorded demand not yet
     * satisfied.
     */
    public function pending(string $kind): ?string
    {
        $row = $this->state?->row($kind);
        if ($row === null) {
            return 'new_workspace';
        }
        $active = $row['active_target'] ?? null;
        if ($this->locator !== null && is_string($active) && $active !== '' && !$this->locator->ownsTarget($active)) {
            return 'engine';
        }
        if ($this->relevantVersion($kind) > (int) $row['reconciled_version']) {
            return 'capability';
        }
        $contributor = $this->sources->all()[$kind] ?? null;
        if ($contributor !== null && $contributor->schemaVersion() !== (int) $row['schema_version']) {
            return 'schema';
        }
        if ($this->state->maxDemandSeq($kind) > (int) $row['satisfied_seq']) {
            return 'demand';
        }
        return null;
    }

    public function relevantVersion(string $kind): int
    {
        if (method_exists($this->flags, 'clearCache')) {
            $this->flags->clearCache();
        }
        $capabilities = ['thallo.search'];
        $contributor = $this->sources->all()[$kind] ?? null;
        if ($contributor !== null) {
            array_push($capabilities, ...$contributor->requiredCapabilities());
        }
        $version = 0;
        foreach (array_unique($capabilities) as $id) {
            $version = max($version, (int) ($this->flags->get('capability.' . $id . '.changed_at') ?? 0));
        }
        return $version;
    }
}
