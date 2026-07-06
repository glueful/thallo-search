<?php

declare(strict_types=1);

namespace Thallo\Search\Http;

use Glueful\Http\Response;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Search\Engine\SearchBackend;
use Thallo\Search\Query\SearchRequest;
use Thallo\Search\Query\VisibilityResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public content search. Behind `optional_api_key`: an authenticated key narrows visibility
 * to its scopes; anonymous sees only public-delivery types. Visibility is resolved from the
 * live type store per request and enforced inside the backend filter, so `total`/pagination
 * are correct and a type flipped private drops out immediately.
 */
final class SearchController
{
    public function __construct(
        private readonly SearchBackend $backend,
        private readonly VisibilityResolver $visibility,
        private readonly ContentTypeReader $types,
        private readonly int $defaultLimit,
        private readonly int $maxLimit,
    ) {
    }

    public function search(Request $request): Response
    {
        $q = trim(self::stringParam($request, 'q'));
        if ($q === '') {
            return Response::error('A non-empty `q` query parameter is required.', 422);
        }

        $locale = trim(self::stringParam($request, 'locale'));
        if ($locale === '') {
            return Response::error('A `locale` query parameter is required.', 422);
        }

        // null = anonymous (no key); an array (possibly empty) = an authenticated key.
        $grantedScopes = $request->attributes->has('api_key_scopes')
            ? array_values(array_filter((array) $request->attributes->get('api_key_scopes', []), 'is_string'))
            : null;
        $ctx = $this->visibility->resolve($grantedScopes);

        $typeSlug = trim(self::stringParam($request, 'type'));
        if ($typeSlug !== '') {
            $typeUuid = $this->types->findUuidBySlug($typeSlug);
            if ($typeUuid === null) {
                return Response::notFound('Content type not found.');
            }
            if (!$this->visibility->isTypeAccessible($ctx, $typeUuid)) {
                return Response::forbidden('This content type requires a scoped API key');
            }
        } else {
            $typeSlug = null;
        }

        $limit = $this->clamp(
            (int) self::stringParam($request, 'limit', (string) $this->defaultLimit),
            1,
            $this->maxLimit,
        );
        $offset = max(0, (int) self::stringParam($request, 'offset', '0'));

        // No health() preflight: it cost two extra Meilisearch round-trips per request to
        // predict what this try/catch handles anyway (backend down/misconfigured → 503).
        try {
            $results = $this->backend->search(new SearchRequest(
                q: $q,
                locale: $locale,
                typeSlug: $typeSlug,
                allAccess: $ctx->allAccess,
                visibleTypeUuids: $ctx->visibleTypeUuids,
                limit: $limit,
                offset: $offset,
            ));
        } catch (\Throwable) {
            return Response::error('Search is temporarily unavailable.', 503);
        }

        $hits = [];
        foreach ($results->hits as $hit) {
            $hits[] = [
                'uuid' => $hit->entryUuid,
                'type' => $hit->contentTypeSlug,
                'locale' => $hit->locale,
                'href' => $hit->href,
                'title' => $hit->title,
                'snippet' => $hit->snippet,
                'score' => $hit->score,
            ];
        }

        return Response::success([
            'hits' => $hits,
            'total' => $results->total,
            'limit' => $results->limit,
            'offset' => $results->offset,
        ]);
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /**
     * Read a query parameter as a string, tolerating malformed array-valued params.
     *
     * This route is public (optional_api_key), so a client sending e.g. `?q[]=a` would otherwise
     * hit Symfony InputBag::get()'s non-scalar guard and surface as an unhandled 500. Reading via
     * all() and treating a non-string value as the default keeps every scalar input identical while
     * turning array inputs into the "absent" case (→ the existing 422 for required params).
     */
    private static function stringParam(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query->all()[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }
}
