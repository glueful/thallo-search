<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

/**
 * Who may write a kind's index state (search block spec §3.5.5): the builder holding the claim for
 * generation G, the drainer holding the drainer lease, or — after promotion clears the owner token —
 * the build that promoted G, completing its work.
 */
final class Fence
{
    public const BUILDER = 'builder';
    public const DRAINER = 'drainer';
    public const PROMOTED = 'promoted';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $token,
        public readonly ?int $generation,
        public readonly string $role,
    ) {
    }

    public static function builder(string $kind, string $token, int $generation): self
    {
        return new self($kind, $token, $generation, self::BUILDER);
    }

    public static function drainer(string $kind, string $token): self
    {
        return new self($kind, $token, null, self::DRAINER);
    }

    public static function promoted(string $kind, int $generation): self
    {
        return new self($kind, null, $generation, self::PROMOTED);
    }

    /** The token acknowledgements record as their writer. */
    public function writer(): string
    {
        return $this->token ?? ('promoted-g' . $this->generation);
    }
}
