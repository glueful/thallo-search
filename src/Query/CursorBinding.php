<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

use Thallo\Contracts\Search\SearchAudience;

/** Everything a cursor is valid for, hashed: q, scope or kind, type, locale, workspace, audience. */
final class CursorBinding
{
    public static function of(SearchInput $in, string $workspace, SearchAudience $audience): string
    {
        return hash('sha256', implode("\0", [
            $in->q,
            $in->scope->binding,
            $in->type ?? '',
            $in->locale,
            $workspace,
            $audience->fingerprint(),
        ]));
    }
}
