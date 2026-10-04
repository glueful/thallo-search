<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

/**
 * What one target was asked to do, the same shape on both engines (search block spec §3.5.2): the
 * target's acknowledgement key and every task it needed. Postgres names no tasks — its writes are
 * committed by the time it returns.
 */
final class TargetReceipt
{
    /** @param list<int> $taskUids */
    public function __construct(
        public readonly string $targetKey,
        public readonly array $taskUids,
    ) {
    }
}
