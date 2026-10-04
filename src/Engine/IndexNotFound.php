<?php

declare(strict_types=1);

namespace Thallo\Search\Engine;

/** A query named an index that does not exist — retired between reading its name and reaching it. */
final class IndexNotFound extends \RuntimeException
{
    public function __construct(public readonly string $uid)
    {
        parent::__construct("Search index '{$uid}' does not exist.");
    }
}
