<?php

declare(strict_types=1);

namespace Thallo\Search\Http;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Context\Context;
use Thallo\Contracts\Search\SearchAudience;
use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Search\Query\SearchInput;
use Thallo\Search\Query\SearchQueryService;
use Thallo\Search\Query\Surface;

/**
 * `GET /_search/suggest`, the Search block's live suggestions (search block spec §3.4). Always the
 * public audience — a signed-in session grants nothing here — in the server's workspace, never
 * cached, and every state distinct: results, no matches, rebuilding, unavailable.
 */
final class SuggestController
{
    private const LIMIT = 6;

    public function __construct(
        private readonly SearchQueryService $search,
        private readonly SearchSourceRegistry $sources,
        private readonly Context $site,
    ) {
    }

    public function suggest(Request $request): Response
    {
        $input = SearchInput::from(
            $request->query->all(),
            Surface::Site,
            $this->site->enabledLocales(),
            $this->site->defaultLocale(),
            array_keys($this->sources->all()),
        );
        $outcome = $this->search->search($input, SearchAudience::public(), self::LIMIT, true);

        $items = [];
        foreach ($outcome->items as $item) {
            $items[] = [
                'kind' => $item->kind,
                'kind_label' => $item->kindLabel,
                'title' => $item->display->title,
                'href' => $item->display->href,
                'image' => $item->display->image,
                'price' => $item->display->price,
                'snippet' => $item->snippetHtml,
            ];
        }
        $seeAll = '/search?' . http_build_query([
            'q' => $input->q,
            'scope' => $input->scope->isAll() ? '' : (string) $input->scope->kind,
            'locale' => $input->locale,
        ]);

        $response = Response::success(['state' => $outcome->state, 'items' => $items, 'see_all' => $seeAll]);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}
