<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Search;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Search\QueryBridge;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Tests\Support\ArrayLogger;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\YetiSearch;

final class QueryBridgeTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('is_admin')->justReturn(false);
    }

    private static function searchQuery(array $vars = []): \WP_Query
    {
        $query = new \WP_Query($vars + ['s' => 'zebra', 'posts_per_page' => 10, 'paged' => 0, 'fields' => '', 'post_type' => '']);
        $query->is_search = true;
        $query->isMain = true;
        return $query;
    }

    private static function bridge(?YetiSearch $yeti, array $settings = ['master_enabled' => true], ?ArrayLogger $logger = null): QueryBridge
    {
        $config = new Config($settings);
        return new QueryBridge($config, new SearchService($yeti, $config, new ResultNormalizer($config)), $logger ?? new ArrayLogger());
    }

    public function testFallsBackToMySqlWhenEngineThrows(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->andThrow(new \RuntimeException('no such table: wp_posts'));
        $logger = new ArrayLogger();

        $result = self::bridge($yeti, ['master_enabled' => true], $logger)->onPostsPreQuery(null, self::searchQuery());

        self::assertNull($result);
        self::assertTrue($logger->hasLevel('error'));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2?: bool}> */
    public static function nonInterceptedQueries(): iterable
    {
        yield 'master off' => [['master_enabled' => false], []];
        yield 'fields=ids' => [['master_enabled' => true], ['fields' => 'ids']];
        yield 'empty term' => [['master_enabled' => true], ['s' => '   ']];
        yield 'unindexed post type' => [['master_enabled' => true], ['post_type' => 'attachment']];
    }

    #[DataProvider('nonInterceptedQueries')]
    public function testDoesNotIntercept(array $settings, array $vars): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldNotReceive('getSearchEngine');

        self::assertNull(self::bridge($yeti, $settings)->onPostsPreQuery(null, self::searchQuery($vars)));
    }

    public function testIgnoresSecondaryAndNonSearchQueriesAndAdmin(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldNotReceive('getSearchEngine');
        $bridge = self::bridge($yeti);

        $secondary = self::searchQuery();
        $secondary->isMain = false;
        $notSearch = self::searchQuery();
        $notSearch->is_search = false;

        self::assertNull($bridge->onPostsPreQuery(null, $secondary));
        self::assertNull($bridge->onPostsPreQuery(null, $notSearch));

        Functions\when('is_admin')->justReturn(true);
        self::assertNull($bridge->onPostsPreQuery(null, self::searchQuery()));
    }

    public function testRespectsPostsAlreadyProvidedByAnotherPlugin(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldNotReceive('getSearchEngine');
        $posts = [new \WP_Post(['ID' => 99])];

        self::assertSame($posts, self::bridge($yeti)->onPostsPreQuery($posts, self::searchQuery()));
    }

    public function testFiltersOnlyApplyInTheLoop(): void
    {
        Functions\when('in_the_loop')->justReturn(false);
        $bridge = self::bridge(null);

        self::assertSame('Plain', $bridge->filterTitle('Plain', 5));
        self::assertSame('Excerpt', $bridge->filterExcerpt('Excerpt', new \WP_Post(['ID' => 5])));
    }

    public function testVerifiedModeRecountsPublicPosts(): void
    {
        Functions\when('in_the_loop')->justReturn(true);
        Functions\when('get_posts')->alias(static fn (array $a): array => ($a['fields'] ?? '') === 'ids'
            ? [11]
            : [new \WP_Post(['ID' => 11, 'post_title' => 'Eleven'])]);
        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->once()->andReturn(new \YetiSearch\Models\SearchResults([
            ['id' => '11', 'score' => 5.0, 'document' => ['title' => 'Eleven', 'url' => 'https://example.test/?p=11'], 'highlights' => [], 'metadata' => ['post_id' => 11, 'post_type' => 'post']],
            ['id' => '12', 'score' => 4.0, 'document' => ['title' => 'Twelve', 'url' => 'https://example.test/?p=12'], 'highlights' => [], 'metadata' => ['post_id' => 12, 'post_type' => 'post']],
        ], 2));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->with(Config::INDEX)->andReturn($engine);

        $query = self::searchQuery();
        $posts = self::bridge($yeti, ['master_enabled' => true, 'search_mode' => 'verified'])->onPostsPreQuery(null, $query);

        self::assertIsArray($posts);
        self::assertCount(1, $posts);
        self::assertSame(1, $query->found_posts);
        self::assertSame(1, $query->max_num_pages);
    }

    public function testTakeoverStashesEnrichedFacets(): void
    {
        Functions\when('get_posts')->alias(static fn (array $a): array => ($a['fields'] ?? '') === 'ids'
            ? [11]
            : [new \WP_Post(['ID' => 11, 'post_title' => 'Eleven'])]);
        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->once()->andReturn(new \YetiSearch\Models\SearchResults([
            ['id' => '11', 'score' => 5.0, 'document' => ['title' => 'Eleven', 'url' => 'https://example.test/?p=11'], 'highlights' => [], 'metadata' => ['post_id' => 11, 'post_type' => 'post']],
        ], 1, 0.0, ['price_range' => [['value' => '0-100', 'count' => 1, 'to' => 100.0]]]));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->with(Config::INDEX)->andReturn($engine);

        $config = new Config(['master_enabled' => true, 'facets_enabled' => true, 'price_facet_enabled' => true, 'price_ranges' => [100.0]]);
        $facets = new \WpYetiSearch\Features\FacetBridge($config);
        $search = new SearchService($yeti, $config, new ResultNormalizer($config), null, $facets);
        $bridge = new QueryBridge($config, $search, new ArrayLogger(), null, $facets);

        $query = self::searchQuery();
        $bridge->onPostsPreQuery(null, $query);

        $facets = $query->get('yetisearch_facets');
        self::assertSame(['price' => ['lte' => 100.0]], $facets['price_range'][0]['filter']);
    }

    public function testZeroPerPageFallsBackToMaxResults(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.test/?p=11');
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_posts')->alias(static fn (array $a): array => ($a['fields'] ?? '') === 'ids'
            ? [11]
            : [new \WP_Post(['ID' => 11, 'post_title' => 'Eleven'])]);
        $seen = [];
        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->once()->with(
            \Mockery::on(static function (\YetiSearch\Models\SearchQuery $q) use (&$seen): bool {
                $seen['limit'] = $q->getLimit();
                return true;
            }),
            \Mockery::any()
        )->andReturn(new \YetiSearch\Models\SearchResults([
            ['id' => '11', 'score' => 5.0, 'document' => ['title' => 'Eleven', 'url' => 'https://example.test/?p=11'], 'highlights' => [], 'metadata' => ['post_id' => 11, 'post_type' => 'post']],
        ], 1));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->with(Config::INDEX)->andReturn($engine);

        $posts = self::bridge($yeti)->onPostsPreQuery(null, self::searchQuery(['posts_per_page' => 0]));

        self::assertCount(1, $posts);
        self::assertGreaterThan(0, $seen['limit']);
    }

    public function testFiltersReturnStoredHighlightsInTheLoop(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.test/?p=11');
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_posts')->alias(static fn (array $a): array => ($a['fields'] ?? '') === 'ids'
            ? [11]
            : [new \WP_Post(['ID' => 11, 'post_title' => 'Eleven'])]);
        Functions\when('in_the_loop')->justReturn(true);
        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->once()->andReturn(new \YetiSearch\Models\SearchResults([
            ['id' => '11', 'score' => 5.0, 'document' => ['title' => 'Eleven', 'url' => 'https://example.test/?p=11'], 'highlights' => ['title' => ['<b>Eleven</b>']], 'metadata' => ['post_id' => 11, 'post_type' => 'post']],
        ], 1));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->with(Config::INDEX)->andReturn($engine);

        $bridge = self::bridge($yeti, ['master_enabled' => true, 'highlight_enabled' => true]);
        $bridge->onPostsPreQuery(null, self::searchQuery());

        self::assertStringContainsString('Eleven', $bridge->filterTitle('Plain', 11));
        self::assertNotSame('Plain', $bridge->filterTitle('Plain', 11));
        self::assertIsString($bridge->filterExcerpt('Excerpt', new \WP_Post(['ID' => 11])));
    }
}
