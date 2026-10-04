<?php

declare(strict_types=1);

namespace Thallo\Search;

use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\DeclaresCapabilities;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Contracts\Search\BlockTextExtractor;
use Thallo\Contracts\Search\ContentReindexer;
use Thallo\Search\Console\ReindexCommand;
use Thallo\Search\Console\StatusCommand;
use Thallo\Search\Engine\LiveMeilisearchIndex;
use Thallo\Search\Engine\MeilisearchBackend;
use Thallo\Search\Engine\PostgresFtsBackend;
use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Search\Engine\SearchBackend;
use Thallo\Search\Sources\DefaultSearchSourceRegistry;
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

final class SearchServiceProvider extends ServiceProvider implements DeclaresLoadOrder, DeclaresCapabilities
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
            SearchSourceRegistry::class => [
                'class' => DefaultSearchSourceRegistry::class, 'shared' => true,
            ],
            \Thallo\Search\Lifecycle\Workspace::class => [
                'class' => \Thallo\Search\Lifecycle\Workspace::class, 'shared' => true, 'autowire' => true,
            ],
            \Thallo\Search\Lifecycle\Clock::class => [
                'class' => \Thallo\Search\Lifecycle\DatabaseClock::class, 'shared' => true, 'autowire' => true,
            ],
            \Thallo\Search\Lifecycle\StateRepository::class => [
                'class' => \Thallo\Search\Lifecycle\StateRepository::class, 'shared' => true, 'autowire' => true,
            ],
            \Thallo\Search\Store\IndexStore::class => [
                'shared' => true, 'factory' => [self::class, 'makeIndexStore'],
            ],
            \Thallo\Search\Lifecycle\SearchIndexLocator::class => [
                'shared' => true, 'factory' => [self::class, 'makeLocator'],
            ],
            \Thallo\Search\Lifecycle\Drainer::class => [
                'shared' => true, 'factory' => [self::class, 'makeDrainer'],
            ],
            \Thallo\Contracts\Search\SearchIndex::class => [
                'shared' => true, 'factory' => [self::class, 'makeSearchIndex'],
            ],
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
                LiveMeilisearchIndex::fromContainer($container),
                $snippetLength,
                (string) config($context, 'search.index', 'content'),
            ),
            default => new UnavailableSearchBackend((string) $why),
        };
    }

    /** The engine's index store, chosen the same way as the engine itself (search block spec §3.3). */
    public static function makeIndexStore(ContainerInterface $container): \Thallo\Search\Store\IndexStore
    {
        $context = $container->get(ApplicationContext::class);
        $db = $container->get(Connection::class);
        [$engine, $why] = SearchEngineChoice::resolve(
            (string) config($context, 'search.engine', 'auto'),
            $db->getDriverName(),
            (bool) config($context, 'search.meilisearch_configured', false),
            $container->has(IndexManager::class),
        );
        return match ($engine) {
            SearchEngineChoice::POSTGRES => new \Thallo\Search\Store\PostgresIndexStore(
                $db,
                $container->get(\Thallo\Search\Lifecycle\StateRepository::class),
            ),
            SearchEngineChoice::MEILISEARCH => new \Thallo\Search\Store\MeilisearchIndexStore(
                LiveMeilisearchIndex::fromContainer($container),
                1000 * (int) config($context, 'search.meilisearch_task_timeout', 10),
                50,
                (string) config($context, 'search.index', 'content'),
            ),
            default => new \Thallo\Search\Store\UnavailableIndexStore((string) $why),
        };
    }

    public static function makeLocator(ContainerInterface $container): \Thallo\Search\Lifecycle\SearchIndexLocator
    {
        $context = $container->get(ApplicationContext::class);
        $store = $container->get(\Thallo\Search\Store\IndexStore::class);
        return new \Thallo\Search\Lifecycle\SearchIndexLocator(
            $container->get(\Thallo\Search\Lifecycle\StateRepository::class),
            $container->get(\Thallo\Search\Lifecycle\Workspace::class),
            $store instanceof \Thallo\Search\Store\MeilisearchIndexStore
                ? \Thallo\Search\Lifecycle\SearchIndexLocator::MEILISEARCH
                : \Thallo\Search\Lifecycle\SearchIndexLocator::POSTGRES,
            (string) config($context, 'search.index', 'content'),
        );
    }

    public static function makeDrainer(ContainerInterface $container): \Thallo\Search\Lifecycle\Drainer
    {
        $context = $container->get(ApplicationContext::class);
        return new \Thallo\Search\Lifecycle\Drainer(
            $container->get(\Thallo\Search\Lifecycle\StateRepository::class),
            $container->get(\Thallo\Search\Store\IndexStore::class),
            $container->get(SearchSourceRegistry::class),
            $container->get(\Thallo\Search\Lifecycle\SearchIndexLocator::class),
            (int) config($context, 'search.drainer_lease', 60),
            (int) config($context, 'search.request_timeout', 10),
            (int) config($context, 'search.lease_margin', 5),
            $container->get(LoggerInterface::class),
        );
    }

    /** Live while search is on; a no-op otherwise (the reconcile on re-enable rebuilds). */
    public static function makeSearchIndex(ContainerInterface $container): \Thallo\Contracts\Search\SearchIndex
    {
        if (!self::enabled($container->get(ApplicationContext::class))) {
            return new \Thallo\Search\Lifecycle\NullSearchIndex();
        }
        return new \Thallo\Search\Lifecycle\LiveSearchIndex(
            $container->get(\Thallo\Search\Lifecycle\StateRepository::class),
            $container->get(\Thallo\Search\Lifecycle\Drainer::class),
            $container->get(Connection::class),
            $container->get(LoggerInterface::class),
        );
    }

    public static function makeDocumentBuilder(ContainerInterface $container): DocumentBuilder
    {
        $context = $container->get(ApplicationContext::class);
        /** @var array<string,array<string,mixed>> $types */
        $types = (array) config($context, 'search.types', []);
        $blockText = $container->has(BlockTextExtractor::class) ? $container->get(BlockTextExtractor::class) : null;

        return new DocumentBuilder($types, $blockText instanceof BlockTextExtractor ? $blockText : null);
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

    public function capabilities(): array
    {
        return [
            new Capability(
                self::CAPABILITY,
                label: 'Search',
                description: 'Public, delivery-parity content search, over PostgreSQL or Meilisearch.',
                // App-owned: the default engine is the site's own database. Meilisearch is an engine
                // a site may choose (SearchEngineChoice), not what the capability depends on.
            ),
        ];
    }

    public function boot(ApplicationContext $context): void
    {

        if (self::enabled($context)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/public-routes.php');

            $this->commands([
                ReindexCommand::class,
                StatusCommand::class,
            ]);
        }
    }
}
