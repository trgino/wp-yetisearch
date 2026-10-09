<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Index;

use Brain\Monkey\Functions;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\BulkIndexer;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Tests\Support\ArrayLogger;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\YetiSearch;

final class BulkIndexerTest extends UnitTestCase
{
    /** @var array<string, mixed> */
    private array $lastArgs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_permalink')->justReturn('https://example.test/');
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_option')->justReturn(false);
    }

    /** get_option serving the stemmed-index record (and NEEDS_REINDEX when asked). */
    private static function recordedOptions(bool $needsReindex = false): void
    {
        Functions\when('get_option')->alias(static fn (string $k, mixed $d = false): mixed => match ($k) {
            Config::STEMMED_INDEXES_OPTION => ['wp_posts' => 'en', 'wp_posts_tr' => 'tr'],
            Config::NEEDS_REINDEX_OPTION => $needsReindex,
            default => $d,
        });
    }

    private function factory(int $maxPages, int $found = 2): \Closure
    {
        return function (array $args) use ($maxPages, $found): \WP_Query {
            $this->lastArgs = $args;
            $query = new \WP_Query($args);
            $query->posts = [
                new \WP_Post(['ID' => 1, 'post_title' => 'One']),
                new \WP_Post(['ID' => 2, 'post_title' => 'Two']),
            ];
            $query->found_posts = $found;
            $query->max_num_pages = $maxPages;
            return $query;
        };
    }

    private function bulk(?YetiSearch $yeti, \Closure $factory, array $settings = []): BulkIndexer
    {
        $config = new Config($settings);
        return new BulkIndexer($yeti, new DocumentMapper($config), $config, new ArrayLogger(), $factory);
    }

    public function testForcedFirstPageClearsThenIndexesOneBatch(): void
    {
        self::recordedOptions();
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('listIndices')->twice()->andReturn([['name' => Config::INDEX]]);
        $yeti->shouldReceive('clear')->once()->with(Config::INDEX)->ordered();
        $yeti->shouldReceive('indexBatch')->once()->with(Config::INDEX, \Mockery::on(static fn (array $docs): bool => count($docs) === 2))->ordered();
        $yeti->shouldNotReceive('deleteByIdPrefix', 'rebuildFts');

        $result = $this->bulk($yeti, $this->factory(2))->run(1, 2, [], true);

        self::assertSame(['processed' => 2, 'indexed' => 2, 'total' => 2, 'finished' => false, 'error' => null], $result);
        self::assertSame('publish', $this->lastArgs['post_status']);
        self::assertFalse($this->lastArgs['has_password']);
        self::assertSame('ID', $this->lastArgs['orderby']);
    }

    public function testForcedRunDropsIndexWhenSchemaChanged(): void
    {
        self::recordedOptions(true);
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('listIndices')->twice()->andReturn([['name' => Config::INDEX]]);
        $yeti->shouldReceive('dropIndex')->once()->with(Config::INDEX);
        $yeti->shouldNotReceive('clear');
        $yeti->shouldReceive('indexBatch')->once();

        $this->bulk($yeti, $this->factory(2))->run(1, 2, [], true);
    }

    public function testEmptyTypeListFinishesImmediately(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldNotReceive('indexBatch');

        $result = $this->bulk($yeti, $this->factory(1))->run(1, 10, ['nope'], false);

        self::assertTrue($result['finished']);
        self::assertSame(0, $result['processed']);
    }

    public function testNullEngineIsUnavailable(): void
    {
        $result = $this->bulk(null, $this->factory(1))->run(1, 10);

        self::assertSame('unavailable', $result['error']);
        self::assertFalse($result['finished']);
    }

    public function testUnmappablePostsAreSkipped(): void
    {
        Functions\when('update_option')->justReturn(true);
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('indexBatch')->never();
        $yeti->shouldReceive('clearCache')->once();
        $factory = static function (array $args): \WP_Query {
            $query = new \WP_Query($args);
            $query->posts = [new \WP_Post(['ID' => 9, 'post_title' => 'Nine', 'post_status' => 'draft'])];
            $query->found_posts = 1;
            $query->max_num_pages = 1;
            return $query;
        };

        $result = $this->bulk($yeti, $factory)->run(1, 10);

        self::assertSame(1, $result['processed']);
        self::assertSame(0, $result['indexed']);
        self::assertTrue($result['finished']);
    }

    public function testNonPostRowsAreSkipped(): void
    {
        Functions\when('update_option')->justReturn(true);
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('indexBatch')->never();
        $yeti->shouldReceive('clearCache')->once();
        $factory = static function (array $args): \WP_Query {
            $query = new \WP_Query($args);
            $query->posts = ['junk'];
            $query->found_posts = 1;
            $query->max_num_pages = 1;
            return $query;
        };

        $result = $this->bulk($yeti, $factory)->run(1, 10);

        self::assertSame(0, $result['processed']);
        self::assertTrue($result['finished']);
    }

    public function testResetFallsBackWhenListingFails(): void
    {
        self::recordedOptions();
        Functions\when('update_option')->justReturn(true);
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('listIndices')->once()->ordered()->andThrow(new \RuntimeException('gone'));
        $yeti->shouldReceive('listIndices')->once()->ordered()->andReturn([['name' => Config::INDEX]]);
        $yeti->shouldReceive('clear')->once()->with(Config::INDEX);
        $yeti->shouldReceive('indexBatch')->once();
        $yeti->shouldReceive('rebuildFts')->once()->with(Config::INDEX);
        $yeti->shouldReceive('clearCache')->once();
        $logger = new ArrayLogger();
        $config = new Config();
        $bulk = new BulkIndexer($yeti, new DocumentMapper($config), $config, $logger, $this->factory(1));

        $bulk->run(1, 2, [], true);

        self::assertTrue($logger->hasLevel('debug'));
    }

    public function testResetDropsIndexWhenClearThrows(): void
    {
        self::recordedOptions();
        Functions\when('update_option')->justReturn(true);
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('listIndices')->twice()->andReturn([['name' => Config::INDEX]]);
        $yeti->shouldReceive('clear')->once()->with(Config::INDEX)->andThrow(new \RuntimeException('no table'));
        $yeti->shouldReceive('dropIndex')->once()->with(Config::INDEX);
        $yeti->shouldReceive('indexBatch')->once();
        $yeti->shouldReceive('rebuildFts')->once()->with(Config::INDEX);
        $yeti->shouldReceive('clearCache')->once();

        $this->bulk($yeti, $this->factory(1))->run(1, 2, [], true);
    }

    public function testIncrementalRunReplacesChunksPerDocument(): void
    {
        self::recordedOptions();
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('listIndices')->once()->andReturn([['name' => Config::INDEX]]);
        $yeti->shouldReceive('deleteByIdPrefix')->once()->with(Config::INDEX, '1#', false);
        $yeti->shouldReceive('deleteByIdPrefix')->once()->with(Config::INDEX, '2#', false);
        $yeti->shouldReceive('indexBatch')->once();

        $this->bulk($yeti, $this->factory(3))->run(2, 2);
    }

    public function testLastPageRebuildsAndClearsReindexFlag(): void
    {
        self::recordedOptions();
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('listIndices')->once()->andReturn([['name' => Config::INDEX]]);
        $yeti->shouldReceive('deleteByIdPrefix');
        $yeti->shouldReceive('indexBatch')->once();
        $yeti->shouldReceive('rebuildFts')->once()->with(Config::INDEX);
        $yeti->shouldReceive('clearCache')->once();
        Functions\expect('update_option')->once()->with(Config::NEEDS_REINDEX_OPTION, false);

        $result = $this->bulk($yeti, $this->factory(1))->run(1, 2);

        self::assertTrue($result['finished']);
    }

    public function testFinishWritesLastReindexTimestamp(): void
    {
        self::recordedOptions();
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('listIndices')->once()->andReturn([['name' => Config::INDEX]]);
        $yeti->shouldReceive('deleteByIdPrefix');
        $yeti->shouldReceive('indexBatch')->once();
        $yeti->shouldReceive('rebuildFts')->once()->with(Config::INDEX);
        $yeti->shouldReceive('clearCache')->once();
        Functions\expect('update_option')->once()->with('yetisearch_last_reindex', \Mockery::type('int'));

        $before = time();
        $this->bulk($yeti, $this->factory(1))->run(1, 2);

        // Acceptance is the mock expectation above; time() bounds guard clock skew only.
        self::assertGreaterThanOrEqual($before - 5, time());
    }

    public function testRequestedPostTypesAreIntersectedWithConfig(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class)->shouldIgnoreMissing();

        $this->bulk($yeti, $this->factory(2))->run(1, 2, ['post', 'attachment']);

        self::assertSame(['post'], $this->lastArgs['post_type']);
    }

    public function testErrorsAreReportedNotThrown(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('deleteByIdPrefix')->andThrow(new \RuntimeException('disk I/O error'));

        $result = $this->bulk($yeti, $this->factory(1))->run(1, 2);

        self::assertSame('disk I/O error', $result['error']);
        self::assertFalse($result['finished']);
    }

    public function testForceBatchesDocumentsPerLanguageIndex(): void
    {
        Functions\when('pll_get_post_language')->alias(static fn (int $id): string => $id === 1 ? 'tr' : 'en');
        Functions\when('pll_default_language')->justReturn('en');
        self::recordedOptions();
        $yeti = \Mockery::mock(YetiSearch::class);
        $yeti->shouldReceive('listIndices')->times(3)->andReturn([['name' => Config::INDEX], ['name' => 'wp_posts_tr']]);
        $yeti->shouldReceive('clear')->once()->with(Config::INDEX);
        $yeti->shouldReceive('clear')->once()->with('wp_posts_tr');
        $yeti->shouldReceive('indexBatch')->once()->with(Config::INDEX, \Mockery::on(static fn (array $docs): bool => count($docs) === 1));
        $yeti->shouldReceive('indexBatch')->once()->with('wp_posts_tr', \Mockery::on(static fn (array $docs): bool => count($docs) === 1));
        $yeti->shouldReceive('rebuildFts')->once()->with(Config::INDEX);
        $yeti->shouldReceive('rebuildFts')->once()->with('wp_posts_tr');
        $yeti->shouldReceive('clearCache')->once();
        Functions\expect('update_option')->once()->with(Config::NEEDS_REINDEX_OPTION, false);

        $result = $this->bulk($yeti, $this->factory(1))->run(1, 2, [], true);

        self::assertTrue($result['finished']);
        self::assertSame(2, $result['indexed']);
    }

    public function testUnavailableEngine(): void
    {
        self::assertSame('unavailable', $this->bulk(null, $this->factory(1))->run(1, 2)['error']);
    }
}
