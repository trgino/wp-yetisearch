<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Features;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Features\CacheBridge;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Tests\Support\ArrayLogger;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Models\SearchResults;
use YetiSearch\Search\SearchEngine;
use YetiSearch\YetiSearch;

final class CacheBridgeTest extends UnitTestCase
{
    public function testWarmupRunsTheFrontEndQueryForEachTerm(): void
    {
        Functions\when('get_option')->justReturn(10);
        $engine = \Mockery::mock(SearchEngine::class);
        $engine->shouldReceive('search')
            ->twice()
            ->with(\Mockery::on(static fn (SearchQuery $q): bool => $q->getLimit() === 10 && $q->getOffset() === 0), ['unique_by_route' => true])
            ->andReturn(new SearchResults([], 0));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->andReturn($engine);
        $config = new Config(['warmup_queries' => ['zebra', 'lion']]);
        $search = new SearchService($yeti, $config, new ResultNormalizer($config));

        $result = (new CacheBridge($yeti, $config, $search, new ArrayLogger()))->warmup();

        self::assertSame(['warmed' => 2, 'failed' => 0], $result);
    }

    public function testClearWithoutEngineReturnsFalse(): void
    {
        $config = new Config();
        $search = new SearchService(null, $config, new ResultNormalizer($config));

        self::assertFalse((new CacheBridge(null, $config, $search, new ArrayLogger()))->clear());
    }

    public function testClearDelegatesAndReportsThrowingEngine(): void
    {
        $config = new Config();
        $search = new SearchService(null, $config, new ResultNormalizer($config));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('clearCache')->once();
        $logger = new ArrayLogger();

        self::assertTrue((new CacheBridge($yeti, $config, $search, $logger))->clear());

        $failing = \Mockery::mock(YetiSearch::class);
        $failing->shouldReceive('clearCache')->once()->andThrow(new \RuntimeException('locked'));

        self::assertFalse((new CacheBridge($failing, $config, $search, $logger))->clear());
        self::assertTrue($logger->hasLevel('warning'));
    }

    public function testWarmupWithoutAvailableSearchReturnsZeros(): void
    {
        $config = new Config(['warmup_queries' => ['zebra']]);
        $search = new SearchService(null, $config, new ResultNormalizer($config));

        self::assertSame(
            ['warmed' => 0, 'failed' => 0],
            (new CacheBridge(null, $config, $search, new ArrayLogger()))->warmup()
        );
    }

    public function testWarmupCountsFailuresWithoutAborting(): void
    {
        Functions\when('get_option')->justReturn(10);
        $engine = \Mockery::mock(SearchEngine::class);
        $engine->shouldReceive('search')->once()->ordered()->andReturn(new SearchResults([], 0));
        $engine->shouldReceive('search')->once()->ordered()->andThrow(new \RuntimeException('boom'));
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('getSearchEngine')->andReturn($engine);
        $config = new Config(['indexed_post_types' => ['post'], 'warmup_queries' => ['ok-term', 'bad-term']]);
        $search = new SearchService($yeti, $config, new ResultNormalizer($config));
        $logger = new ArrayLogger();

        $result = (new CacheBridge($yeti, $config, $search, $logger))->warmup();

        self::assertSame(['warmed' => 1, 'failed' => 1], $result);
        self::assertTrue($logger->hasLevel('warning'));
    }

    public function testStatsReturnsEmptyWithoutEngineOrOnFailure(): void
    {
        $config = new Config();
        $search = new SearchService(null, $config, new ResultNormalizer($config));

        self::assertSame([], (new CacheBridge(null, $config, $search, new ArrayLogger()))->stats());

        $failing = \Mockery::mock(YetiSearch::class);
        $failing->shouldReceive('getCacheStats')->once()->andThrow(new \RuntimeException('gone'));

        self::assertSame([], (new CacheBridge($failing, $config, $search, new ArrayLogger()))->stats());
    }
}
