<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

/**
 * Which kinds a query asks for: every available kind, one kind, or a supplied scope that names
 * nothing searchable — which stays unavailable and never widens to everything (search block spec
 * §3.4). `binding` is what a cursor signs.
 */
final class ScopeChoice
{
    private function __construct(
        public readonly ?string $kind,
        private readonly bool $unavailable,
        public readonly string $binding,
    ) {
    }

    public static function allKinds(): self
    {
        return new self(null, false, '');
    }

    public static function kind(string $kind): self
    {
        return new self($kind, false, $kind);
    }

    public static function unavailable(string $supplied): self
    {
        return new self(null, true, '!' . $supplied);
    }

    public function isAll(): bool
    {
        return $this->kind === null && !$this->unavailable;
    }

    public function isUnavailable(): bool
    {
        return $this->unavailable;
    }
}
