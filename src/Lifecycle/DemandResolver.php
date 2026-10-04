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
    ) {
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
