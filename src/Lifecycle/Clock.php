<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

/** The time lease decisions are made against: UTC `Y-m-d H:i:s`. */
interface Clock
{
    public function now(): string;
}
