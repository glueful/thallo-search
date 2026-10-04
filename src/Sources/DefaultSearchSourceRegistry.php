<?php

declare(strict_types=1);

namespace Thallo\Search\Sources;

use Thallo\Contracts\Search\SearchIdentity;
use Thallo\Contracts\Search\SearchSourceContributor;
use Thallo\Contracts\Search\SearchSourceRegistry;

/** One contributor per result kind, in registration order (search block spec §3.2). */
final class DefaultSearchSourceRegistry implements SearchSourceRegistry
{
    /** @var array<string, SearchSourceContributor> */
    private array $contributors = [];

    public function register(SearchSourceContributor $contributor): void
    {
        $kind = $contributor->kind();
        if (preg_match(SearchIdentity::KIND, $kind) !== 1) {
            throw new \LogicException("Search kind '{$kind}' is not a valid kind name.");
        }
        if (isset($this->contributors[$kind])) {
            throw new \LogicException("Search kind '{$kind}' is already provided.");
        }
        $this->contributors[$kind] = $contributor;
    }

    public function all(): array
    {
        return $this->contributors;
    }
}
