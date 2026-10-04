<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Thallo\Search\Store\IndexNameFor;
use Thallo\Search\Store\Target;

/**
 * The only resolver of "which index" (search block spec §3.3): a kind's active and building targets
 * for the current workspace, read from its state row, and the physical target a new build attempt
 * writes into. On Postgres every target is the one table at a generation.
 */
final class SearchIndexLocator
{
    public const POSTGRES = 'pg';
    public const MEILISEARCH = 'meilisearch';

    public function __construct(
        private readonly StateRepository $state,
        private readonly Workspace $workspace,
        private readonly string $engine,
        private readonly string $index,
    ) {
    }

    public function engine(): string
    {
        return $this->engine;
    }

    /** @return array{active: ?Target, building: ?Target} */
    public function targets(string $kind): array
    {
        $row = $this->state->row($kind);
        if ($row === null) {
            return ['active' => null, 'building' => null];
        }
        return [
            'active' => self::target($this->engine, $row['active_target'] ?? null, (int) $row['generation']),
            'building' => $row['building_generation'] === null ? null
                : self::target($this->engine, $row['building_target'] ?? null, (int) $row['building_generation']),
        ];
    }

    /** The targets a live change must reach, keyed by acknowledgement key. @return array<string, Target> */
    public function currentTargets(string $kind): array
    {
        $out = [];
        foreach ($this->targets($kind) as $target) {
            if ($target !== null) {
                $out[$target->key()] = $target;
            }
        }
        return $out;
    }

    /**
     * What a query reads for a kind (search block spec §3.5.6, §3.5.9): `v2` (its promoted target),
     * `empty` (cut over, but this kind has no promoted target yet), `legacy` (entries before the
     * cutover, where reading the old documents is safe — Postgres, whose legacy rows belong to the
     * workspace, or a single-store Meilisearch site), or `rebuilding` (a workspace with enforced
     * tenancy whose isolated index is not ready, which never reads the shared legacy index).
     */
    public function readMode(string $kind): string
    {
        $row = $this->state->row($kind) ?? $this->state->row('entries');
        $format = (string) ($row['format'] ?? 'legacy');
        if ($format === 'v2') {
            $own = $this->state->row($kind);
            return $own !== null && (string) ($own['active_target'] ?? '') !== '' ? 'v2' : 'empty';
        }
        if ($kind !== 'entries') {
            return 'empty';
        }
        $legacySafe = $this->engine === self::POSTGRES || !$this->workspace->enforcementActive();
        return $legacySafe ? 'legacy' : 'rebuilding';
    }

    public function buildTarget(string $kind, int $generation): Target
    {
        if ($this->engine === self::POSTGRES) {
            return new Target(self::POSTGRES, Target::POSTGRES, $generation);
        }
        return new Target(
            self::MEILISEARCH,
            IndexNameFor::target($this->index, $this->workspace(), $kind, $generation),
            $generation,
        );
    }

    /** Every attempt's target name for a kind in this workspace starts with this (Meilisearch). */
    public function targetPrefix(string $kind): string
    {
        return IndexNameFor::prefix($this->index, $this->workspace(), $kind);
    }

    public function workspace(): ?string
    {
        return $this->workspace->current();
    }

    private static function target(string $engine, mixed $name, int $generation): ?Target
    {
        if (!is_string($name) || $name === '') {
            return null;
        }
        return new Target($engine === self::POSTGRES ? self::POSTGRES : self::MEILISEARCH, $name, $generation);
    }
}
