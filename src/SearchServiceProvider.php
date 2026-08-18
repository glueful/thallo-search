<?php

declare(strict_types=1);

namespace Thallo\Search;

use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Contracts\Search\ContentReindexer;
use Thallo\Search\Console\ReindexCommand;
use Thallo\Search\Console\StatusCommand;
use Thallo\Search\Engine\LiveMeilisearchIndex;
use Thallo\Search\Engine\MeilisearchBackend;
use Thallo\Search\Engine\SearchBackend;
use Thallo\Search\Http\SearchController;
use Thallo\Search\Index\DocumentBuilder;
use Thallo\Search\Index\NullContentReindexer;
use Thallo\Search\Index\ResilientContentReindexer;
use Thallo\Search\Index\SearchContentReindexer;
use Thallo\Search\Query\VisibilityResolver;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class SearchServiceProvider extends ServiceProvider implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [];
    }

    /**
     * Post-extension tier (modules-not-extensions spec §5.2): app-integrated modules load
     * AFTER the extension universe, reproducing the pre-conversion order in which they lived
     * at the tail of config/extensions.php. Inter-module order comes from the
     * serviceproviders.php list (the orderer's stable tie-break).
     */
    public static function loadPriority(): int
    {
        return 100;
    }

    private const CAPABILITY = 'thallo.search';

    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            SearchBackend::class => [
                'shared' => true, 'factory' => [self::class, 'makeSearchBackend'],
            ],
            DocumentBuilder::class => [
                'shared' => true, 'factory' => [self::class, 'makeDocumentBuilder'],
            ],
            SearchContentReindexer::class => [
                'class' => SearchContentReindexer::class, 'shared' => true, 'autowire' => true,
            ],
            ContentReindexer::class => [
                'shared' => true, 'factory' => [self::class, 'makeContentReindexer'],
            ],
            VisibilityResolver::class => [
                'class' => VisibilityResolver::class, 'shared' => true, 'autowire' => true,
            ],
            SearchController::class => [
                'shared' => true, 'factory' => [self::class, 'makeSearchController'],
            ],
            ReindexCommand::class => [
                'class' => ReindexCommand::class, 'shared' => true, 'autowire' => true,
            ],
            StatusCommand::class => [
                'class' => StatusCommand::class, 'shared' => true, 'autowire' => true,
            ],
        ];
    }

    public static function makeSearchController(ContainerInterface $container): SearchController
    {
        $context = $container->get(ApplicationContext::class);
        return new SearchController(
            $container->get(SearchBackend::class),
            $container->get(VisibilityResolver::class),
            $container->get(ContentTypeReader::class),
            (int) config($context, 'search.default_limit', 20),
            (int) config($context, 'search.max_limit', 50),
        );
    }

    public static function makeSearchBackend(ContainerInterface $container): MeilisearchBackend
    {
        $context = $container->get(ApplicationContext::class);
        $indexName = (string) config($context, 'search.index', 'content');
        $snippetLength = (int) config($context, 'search.snippet_length', 40);

        return new MeilisearchBackend(
            LiveMeilisearchIndex::fromContainer($container, $indexName),
            $snippetLength,
        );
    }

    public static function makeDocumentBuilder(ContainerInterface $container): DocumentBuilder
    {
        $context = $container->get(ApplicationContext::class);
        /** @var array<string,array<string,mixed>> $types */
        $types = (array) config($context, 'search.types', []);
        return new DocumentBuilder($types);
    }

    public static function makeContentReindexer(ContainerInterface $container): ContentReindexer
    {
        // Disabled ⇒ no-op reindexer (the App listener resolves this and does nothing).
        if (!self::enabled($container->get(ApplicationContext::class))) {
            return new NullContentReindexer();
        }

        return new ResilientContentReindexer(
            // The container owns SearchContentReindexer's wiring (registered autowired above)
            // — never duplicate its dependency list here.
            $container->get(SearchContentReindexer::class),
            $container->get(LoggerInterface::class),
        );
    }

    /** The single capability gate — every gated surface (bindings, routes, commands) uses this. */
    private static function enabled(ApplicationContext $context): bool
    {
        return app($context, CapabilityRegistry::class)->isEnabled(self::CAPABILITY);
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge the pack's own tree under 'search'.
        $this->mergeConfig('search', require __DIR__ . '/../config/search.php');
    }

    public function boot(ApplicationContext $context): void
    {
        app($context, CapabilityRegistry::class)->register(new Capability(
            self::CAPABILITY,
            label: 'Search',
            description: 'Public, delivery-parity content search backed by Meilisearch.',
            owningPackage: 'glueful/meilisearch',
        ));

        if (self::enabled($context)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/public-routes.php');

            $this->commands([
                ReindexCommand::class,
                StatusCommand::class,
            ]);
        }
    }
}
