<?php

declare(strict_types=1);

namespace Thallo\Search\Http;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Context\Context;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Contracts\Search\SearchAudience;
use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Search\Lifecycle\Workspace;
use Thallo\Search\Query\CursorBinding;
use Thallo\Search\Query\CursorSigner;
use Thallo\Search\Query\InvalidSearchInput;
use Thallo\Search\Query\SearchInput;
use Thallo\Search\Query\SearchOutcome;
use Thallo\Search\Query\SearchQueryService;
use Thallo\Search\Query\Surface;
use Thallo\Search\Query\VisibilityResolver;

/**
 * `GET /v1/search`, a thin adapter over the shared query service (search block spec §3.4). Behind
 * `optional_api_key`: a key narrows visibility to its scopes, anonymous sees public types. It keeps
 * its entries-only default (`kind` adds `all` and `products`), its `type` filter and its 404/403/422
 * answers, and its fixed `offset` windows; `cursor` is the opt-in continuation that refills.
 */
final class SearchController
{
    public function __construct(
        private readonly SearchQueryService $search,
        private readonly SearchSourceRegistry $sources,
        private readonly VisibilityResolver $visibility,
        private readonly ContentTypeReader $types,
        private readonly Context $site,
        private readonly CursorSigner $cursors,
        private readonly Workspace $workspace,
        private readonly int $defaultLimit,
        private readonly int $maxLimit,
    ) {
    }

    public function search(Request $request): Response
    {
        $query = $request->query->all();
        try {
            $input = SearchInput::from(
                $query,
                Surface::Api,
                $this->site->enabledLocales(),
                $this->site->defaultLocale(),
                array_keys($this->sources->all()),
            );
        } catch (InvalidSearchInput $e) {
            return Response::error($e->getMessage(), $e->status);
        }

        // null = anonymous (no key); an array (possibly empty) = an authenticated key.
        $scopes = $request->attributes->has('api_key_scopes')
            ? array_values(array_filter((array) $request->attributes->get('api_key_scopes', []), 'is_string'))
            : null;
        $audience = $scopes === null ? SearchAudience::public() : SearchAudience::apiKey($scopes);

        $typeUuid = null;
        if ($input->type !== null) {
            $typeUuid = $this->types->findUuidBySlug($input->type);
            if ($typeUuid === null) {
                return Response::notFound('Content type not found.');
            }
            if (!$this->visibility->isTypeAccessible($this->visibility->resolve($scopes), $typeUuid)) {
                return Response::forbidden('This content type requires a scoped API key');
            }
        }

        $rawLimit = $query['limit'] ?? null;
        $limit = max(1, min($this->maxLimit, is_string($rawLimit) ? (int) $rawLimit : $this->defaultLimit));

        if ($input->rawCursor !== null) {
            $binding = CursorBinding::of($input, $this->workspace->current() ?? '', $audience);
            if ($this->cursors->verify($input->rawCursor, $binding) === null) {
                return Response::error('The `cursor` is not valid for this query.', 400);
            }
        }
        $outcome = $this->search->search($input, $audience, $limit, $input->rawCursor !== null, $typeUuid);
        return match ($outcome->state) {
            SearchOutcome::UNAVAILABLE, SearchOutcome::REBUILDING => Response::error(
                'Search is temporarily unavailable.',
                503,
            ),
            SearchOutcome::SCOPE_UNAVAILABLE => Response::error(
                'That search kind is not available'
                . ($outcome->unavailableReason !== null ? ': ' . $outcome->unavailableReason : '') . '.',
                422,
            ),
            default => Response::success($this->envelope($outcome, $limit, $input->offset)),
        };
    }

    /** @return array<string, mixed> */
    private function envelope(SearchOutcome $outcome, int $limit, ?int $offset): array
    {
        $slugs = [];
        foreach ($this->types->deliveryTypes() as $uuid => $type) {
            $slugs[(string) $uuid] = (string) $type['slug'];
        }
        $hits = [];
        foreach ($outcome->items as $item) {
            $hit = ['kind' => $item->kind];
            if ($item->kind === 'entries') {
                $hit['uuid'] = $item->sourceId;
                $hit['type'] = $item->subtype !== null ? ($slugs[$item->subtype] ?? null) : null;
            } else {
                $hit['source_id'] = $item->sourceId;
            }
            $hit += [
                'locale' => $item->locale,
                'href' => $item->display->href,
                'title' => $item->display->title,
                'snippet' => $item->snippetHtml,
                'score' => $item->score,
            ];
            if ($item->display->image !== null) {
                $hit['image'] = $item->display->image;
            }
            if ($item->display->price !== null) {
                $hit['price'] = $item->display->price;
            }
            $hits[] = $hit;
        }
        return [
            'hits' => $hits,
            'total' => $outcome->total,
            'total_approximate' => $outcome->totalApproximate,
            'limit' => $limit,
            'offset' => $offset ?? 0,
            'next' => $outcome->next,
        ];
    }
}
