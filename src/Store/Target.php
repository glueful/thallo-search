<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

/**
 * A physical place documents are written to (search block spec §3.3): a Meilisearch index, unique
 * to one build attempt, or the generation of the Postgres table. `key()` is the acknowledgement
 * identity — on Postgres every generation shares the physical name `pg`, so the name alone never
 * identifies a target there.
 */
final class Target
{
    public const POSTGRES = 'pg';

    public function __construct(
        public readonly string $engine,
        public readonly string $name,
        public readonly int $generation,
    ) {
    }

    public function key(): string
    {
        return self::keyFor($this->name, $this->generation);
    }

    public static function keyFor(string $name, int $generation): string
    {
        return $name === self::POSTGRES ? 'pg:g' . $generation : $name;
    }
}
