<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

/** Whether the engine can answer, and if not, the message an operator is shown verbatim. */
final class Readiness
{
    public function __construct(
        public readonly bool $available,
        public readonly ?string $message = null,
        public readonly ?string $version = null,
    ) {
    }
}
