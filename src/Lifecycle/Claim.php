<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

/**
 * One build attempt's ownership (search block spec §3.5.3): a generation unique to it, and what it
 * captured when it began — the journal head, the demand, the capability version and the schema
 * version — so completion acknowledges only what this attempt actually built.
 */
final class Claim
{
    public function __construct(
        public readonly string $kind,
        public readonly string $token,
        public readonly int $generation,
        public readonly int $journalStartSeq,
        public readonly int $demandSeqAtStart,
        public readonly int $versionAtStart,
        public readonly int $schemaVersionAtStart,
    ) {
    }
}
