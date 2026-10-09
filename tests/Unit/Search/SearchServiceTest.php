<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Search;

use WpYetiSearch\Core\Config;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Models\SearchResults;
use YetiSearch\Search\SearchEngine;
use YetiSearch\YetiSearch;

final class SearchServiceTest extends UnitTestCase
{
    public function testFrontQueryAppliesPagingFilterFuzzyAndHighlight(): void
    {
        $service = new SearchService(null, new Config(['enable_fuzzy' => true, 'snippet_length' => 120]), new ResultNormalizer(new Config()));

        $query = $service->frontQuery('zebra', ['post', 'page'], 10, 3);

        self::assertSame(10, $query->getLimit());
        self::assertSame(20, $query->getOffset());
        self::assertTrue($query->isFuzzy());
        self::assertSame(120, $query->getHighlightLength());
        self::assertSame([['field' => 'metadata.post_type', 'value' => ['post', 'page'], 'operator' => 'in']], $query->getFilters());
    }

    public function testNewQueryAppliesConfiguredStemmerLanguage(): void
    {
        $service = new SearchService(null, new Config(['stemmer_language' => 'tr']), new ResultNormalizer(new Config()));

        $query = $service->newQuery('vapur');

        self::assertSame('tr', $query->getLanguage());
    }

    public function testIndexStatsReturnsEmptyOnFailure(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('countDocuments')->once()->andThrow(new \RuntimeException('gone'));
        $service = new SearchService($yeti, new Config(), new ResultNormalizer(new Config()));

        self::assertSame([], $service->indexStats());
    }

    public function testRunAlwaysDeduplicatesAndForwardsSemanticFlag(): void
    {
        $engine = \Mockery::mock(SearchEngine::class);
        $engine->shouldReceive('search')
            ->once()
            ->with(\Mockery::type(SearchQuery::class), ['unique_by_route' => true, 'semantic' => false])
            ->andReturn(new SearchResults([], 0));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->with(Config::INDEX)->andReturn($engine);
        $service = new SearchService($yeti, new Config(), new ResultNormalizer(new Config()));

        $result = $service->run($service->newQuery('zebra'), ['semantic' => false]);

        self::assertSame(['items' => [], 'total' => 0, 'suggestion' => null, 'facets' => [], 'search_time' => 0.0, 'semantic' => false], $result);
    }

    public function testRunThrowsWhenUnavailable(): void
    {
        $service = new SearchService(null, new Config(), new ResultNormalizer(new Config()));

        $this->expectException(\RuntimeException::class);
        $service->run($service->newQuery('zebra'));
    }
}
