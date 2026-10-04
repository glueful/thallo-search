<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

/** A query the API refuses, with the status it answers (422, or 400 for a bad cursor). */
final class InvalidSearchInput extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
