<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

enum RebuildOutcome: string
{
    /** Another attempt holds the claim; its completion covers this request, or demand reruns it. */
    case BUSY = 'busy';
    case PROMOTED = 'promoted';
    /** A batch did not complete, or the build could not catch up; nothing was swept. */
    case FAILED = 'failed';
    /** The claim was taken over mid-run; the replacement restarts. */
    case LOST = 'lost';
}
