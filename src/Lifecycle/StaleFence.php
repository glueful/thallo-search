<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

/** The writer no longer holds the fence it wrote under; its transaction is rolled back. */
final class StaleFence extends \RuntimeException
{
}
