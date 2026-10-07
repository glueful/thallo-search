<?php

declare(strict_types=1);

namespace Thallo\Search\Http;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Context\Context;
use Thallo\Contracts\Search\SearchAudience;
use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\Layouts\FramePresentation;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\SiteContext;
use Thallo\Render\TwigFactory;
use Thallo\Search\Query\KindAvailability;
use Thallo\Search\Query\SearchInput;
use Thallo\Search\Query\SearchOutcome;
use Thallo\Search\Query\SearchQueryService;
use Thallo\Search\Query\SnippetBuilder;
use Thallo\Search\Query\Surface;

/**
 * `GET /search`, the themed results page (search block spec §3.7). Registered whether or not search
 * is on, so the path answers the theme's 404 while it is off. It works without JavaScript, shows a
 * distinct message for each outcome, carries scope and locale through every link and form, and is
 * never cached and never indexed.
 */
final class SearchPageController
{
    private const RETRY_AFTER = 120;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TwigFactory $twig,
        private readonly RenderContextExtension $extension,
        private readonly CapabilityRegistry $capabilities,
        private readonly SearchSourceRegistry $sources,
        private readonly Context $site,
        private readonly SearchPageThrottle $throttle,
        private readonly int $pageSize,
    ) {
    }

    public function page(Request $request): Response
    {
        if (!$this->capabilities->isEnabled('thallo.search')) {
            return $this->render($request, '404.twig', [], 404, $this->site->defaultLocale());
        }
        $input = SearchInput::from(
            $request->query->all(),
            Surface::Site,
            $this->site->enabledLocales(),
            $this->site->defaultLocale(),
            array_keys($this->sources->all()),
        );
        $rawScope = $request->query->all()['scope'] ?? '';
        $scope = is_string($rawScope) ? mb_substr($rawScope, 0, 32) : '';

        if (!$this->throttle->hit((string) $request->getClientIp())) {
            $response = $this->results($request, $input, $scope, new SearchOutcome('rate_limited'), 429);
            $response->headers->set('Retry-After', (string) $this->throttle->secondsLeft());
            return $response;
        }

        $search = $this->context->getContainer()->get(SearchQueryService::class);
        $outcome = $search->search($input, SearchAudience::public(), $this->pageSize, true);
        $unavailable = in_array($outcome->state, [SearchOutcome::REBUILDING, SearchOutcome::UNAVAILABLE], true);
        $response = $this->results($request, $input, $scope, $outcome, $unavailable ? 503 : 200);
        if ($unavailable) {
            $response->headers->set('Retry-After', (string) self::RETRY_AFTER);
        }
        return $response;
    }

    private function results(
        Request $request,
        SearchInput $input,
        string $scope,
        SearchOutcome $outcome,
        int $status,
    ): Response {
        $items = [];
        foreach ($outcome->items as $item) {
            $items[] = [
                'title' => $item->display->title,
                'href' => $item->display->href,
                'image' => $item->display->image,
                'price' => $item->display->price,
                'kind_label' => $item->kindLabel,
                // Plain segments the template escapes itself, with <mark> around the matches.
                'snippet' => array_map(
                    static fn (array $part): array => ['text' => $part[0], 'mark' => $part[1]],
                    SnippetBuilder::parts($item->display->text, $input->q),
                ),
            ];
        }
        $link = static fn (array $query): string => '/search?' . http_build_query($query);
        // One tab per kind searchable now, after "All" — each a plain scope link, so the tabs work
        // without JavaScript. None when there is only one kind to search.
        $availability = $this->context->getContainer()->get(KindAvailability::class);
        $kinds = [];
        foreach ($this->sources->all() as $kind => $contributor) {
            if ($availability->isAvailable($kind)) {
                $kinds[] = [
                    'label' => $contributor->label(),
                    'url' => $link(['q' => $input->q, 'scope' => $kind, 'locale' => $input->locale]),
                    'active' => $scope === $kind,
                ];
            }
        }
        $tabs = count($kinds) < 2 ? [] : [[
            'label' => 'All',
            'url' => $link(['q' => $input->q, 'scope' => '', 'locale' => $input->locale]),
            'active' => $input->scope->isAll(),
        ], ...$kinds];
        return $this->render($request, 'search/results.twig', ['search' => [
            'q' => $input->q,
            'scope' => $scope,
            'all_kinds' => $input->scope->isAll(),
            'locale' => $input->locale,
            'state' => $outcome->state,
            'items' => $items,
            'total' => $outcome->total,
            'scope_label' => $outcome->scopeLabel,
            'more_url' => $outcome->next === null ? null
                : $link(['q' => $input->q, 'scope' => $scope, 'locale' => $input->locale, 'cursor' => $outcome->next]),
            'everything_url' => $link(['q' => $input->q, 'scope' => '', 'locale' => $input->locale]),
            'tabs' => $tabs,
        ]], $status, $input->locale);
    }

    /** @param array<string, mixed> $extra */
    private function render(Request $request, string $template, array $extra, int $status, string $locale): Response
    {
        $this->extension->resetTags();
        $this->extension->resetPerRenderState();
        $this->extension->setAssetContext(null, null);
        $this->extension->setAnnotationScope('none');
        $this->extension->setThemeAppearanceOverride(null, null);
        $this->extension->setLocale($locale);
        $html = $this->extension->finish($this->twig->environment()->render($template, [
            'site' => SiteContext::build($this->context, $locale),
            'current_path' => RenderPageCache::normalizePath($request->getPathInfo()),
            'presentation' => FramePresentation::fixed(null),
            // The theme's seo_head() writes the robots tag (spec §3.7: never indexed).
            'seo' => ['robots' => 'noindex', 'og' => ['type' => 'website']],
        ] + $extra));

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
