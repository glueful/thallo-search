<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

/** Who reads a query (search block spec §3.4): the site's own surfaces, or the public API. */
enum Surface
{
    /** The block, suggestions and the results page: `scope`, a forgiving locale. */
    case Site;
    /** `/v1/search`: `kind` (entries by default), `type`, and 422 where it always answered 422. */
    case Api;
}
