<?php

declare(strict_types=1);

namespace Thallo\Search;

use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Contracts\Search\ContentReindexer;
use Thallo\Search\Console\ReindexCommand;
use Thallo\Search\Console\StatusCommand;
use Thallo\Search\Engine\LiveMeilisearchIndex;
use Thallo\Search\Engine\MeilisearchBackend;
use Thallo\Search\Engine\PostgresFtsBackend;
use Thallo\Search\Engine\SearchBackend;
use Thallo\Search\Engine\SearchEngineChoice;
use Thallo\Search\Engine\UnavailableSearchBackend;
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

    public static function makeSearchBackend(ContainerInterface $container): SearchBackend
    {
        $context = $container->get(ApplicationContext::class);
        $snippetLength = (int) config($context, 'search.snippet_length', 40);
        $db = $container->get(Connection::class);

        [$engine, $why] = SearchEngineChoice::resolve(
            (string) config($context, 'search.engine', 'auto'),
            $db->getDriverName(),
            (bool) config($context, 'search.meilisearch_configured', false),
            $container->has(IndexManager::class),
        );

        return match ($engine) {
            SearchEngineChoice::POSTGRES => new PostgresFtsBackend($db, $snippetLength),
            SearchEngineChoice::MEILISEARCH => new MeilisearchBackend(
                LiveMeilisearchIndex::fromContainer($container, (string) config($context, 'search.index', 'content')),
                $snippetLength,
            ),
            default => new UnavailableSearchBackend((string) $why),
        };
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
            description: 'Public, delivery-parity content search, over PostgreSQL or Meilisearch.',
            // App-owned: the default engine is the site's own database. Meilisearch is an engine
            // a site may choose (SearchEngineChoice), not what the capability depends on.
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
