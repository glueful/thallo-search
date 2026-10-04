<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

/** A receipt's confirmed outcome, naming the tasks that have not finished yet. */
final class ReceiptOutcome
{
    /** @param list<int> $outstandingTaskUids */
    public function __construct(
        public readonly ConfirmResult $result,
        public readonly array $outstandingTaskUids = [],
    ) {
    }

    public static function succeeded(): self
    {
        return new self(ConfirmResult::SUCCEEDED);
    }
}
