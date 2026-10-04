<?php

declare(strict_types=1);

namespace Thallo\Search\Store;

enum ConfirmResult: string
{
    /** Every task in the receipt succeeded. */
    case SUCCEEDED = 'succeeded';
    /** Every task finished, and at least one failed or was canceled. */
    case FAILED = 'failed';
    /** At least one task is still enqueued or processing — even if another already failed. */
    case PENDING = 'pending';
}
