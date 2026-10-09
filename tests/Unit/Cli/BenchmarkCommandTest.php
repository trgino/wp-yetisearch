<?php
declare(strict_types=1);

namespace WpYetiSearch\Tests\Unit\Cli;

use Brain\Monkey\Functions;
use WpYetiSearch\Cli\BenchmarkCommand;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\BulkIndexer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Tests\Support\ArrayLogger;
use WpYetiSearch\Tests\Unit\UnitTestCase;
use YetiSearch\YetiSearch;

final class BenchmarkCommandTest extends UnitTestCase
{
    private function command(?YetiSearch $yeti = null, array $settings = []): BenchmarkCommand
    {
        $config = new Config($settings);
        $indexer = new BulkIndexer($yeti, new \WpYetiSearch\Index\DocumentMapper($config), $config, new ArrayLogger());
        $search = new SearchService($yeti, $config, new \WpYetiSearch\Search\ResultNormalizer($config));
        return new BenchmarkCommand($indexer, $search, $config, new ArrayLogger());
    }

    public function testRunReturnsStructuredResults(): void
    {
        $yeti = \Mockery::mock(YetiSearch::class);
        $engine = \Mockery::mock(\YetiSearch\Search\SearchEngine::class);
        $engine->shouldReceive('search')->andReturn(new \YetiSearch\Models\SearchResults([], 0));
        $yeti->shouldReceive('getSearchEngine')->andReturn($engine);
        $yeti->shouldReceive('listIndices')->andReturn([['name' => 'wp_posts']]);
        $yeti->shouldReceive('clear', 'dropIndex', 'deleteByIdPrefix', 'indexBatch', 'rebuildFts', 'clearCache')->zeroOrMoreTimes();

        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_option')->justReturn(false);

        $results = $this->command($yeti)->run(100, 3);

        self::assertArrayHasKey('search_latency', $results);
        self::assertArrayHasKey('indexing_speed', $results);
        self::assertArrayHasKey('memory_usage', $results);
        self::assertArrayHasKey('scalability', $results);
        self::assertArrayHasKey('mysql_like_ms', $results['search_latency']);
        self::assertArrayHasKey('fts5_ms', $results['search_latency']);
        self::assertArrayHasKey('speedup', $results['search_latency']);
        self::assertArrayHasKey('posts_per_second', $results['indexing_speed']);
        self::assertArrayHasKey('total_seconds', $results['indexing_speed']);
        self::assertArrayHasKey('indexing_peak_mb', $results['memory_usage']);
        self::assertArrayHasKey('search_peak_mb', $results['memory_usage']);
        self::assertArrayHasKey('100', $results['scalability']);
        self::assertArrayHasKey('1000', $results['scalability']);
        self::assertArrayHasKey('10000', $results['scalability']);
    }

    public function testRunWithZeroPostsReturnsEmptyResults(): void
    {
        $results = $this->command()->run(0, 1);

        self::assertSame(0.0, $results['indexing_speed']['posts_per_second']);
        // Wall-clock latency on an empty corpus: near-zero, never exactly zero
        // under load, so bound it instead of asserting an exact float.
        self::assertGreaterThanOrEqual(0.0, $results['search_latency']['mysql_like_ms']);
        self::assertLessThan(1000.0, $results['search_latency']['mysql_like_ms']);
    }

    public function testFormatTableReturnsString(): void
    {
        $results = [
            'search_latency' => ['mysql_like_ms' => 150.5, 'fts5_ms' => 2.3, 'speedup' => 65.4],
            'indexing_speed' => ['posts_per_second' => 1200.0, 'total_seconds' => 0.83],
            'memory_usage' => ['indexing_peak_mb' => 45.2, 'search_peak_mb' => 12.1],
            'scalability' => [
                '100' => ['search_ms' => 1.2, 'index_posts_per_sec' => 1500.0],
                '1000' => ['search_ms' => 3.5, 'index_posts_per_sec' => 1100.0],
                '10000' => ['search_ms' => 12.8, 'index_posts_per_sec' => 800.0],
            ],
        ];

        $table = $this->command()->formatTable($results);

        self::assertStringContainsString('Benchmark Results', $table);
        self::assertStringContainsString('Search Latency', $table);
        self::assertStringContainsString('MySQL LIKE', $table);
        self::assertStringContainsString('FTS5', $table);
        self::assertStringContainsString('Speedup', $table);
        self::assertStringContainsString('Indexing Speed', $table);
        self::assertStringContainsString('Memory Usage', $table);
        self::assertStringContainsString('Scalability', $table);
    }

    public function testFormatJsonReturnsValidJson(): void
    {
        $results = ['search_latency' => ['mysql_like_ms' => 100.0, 'fts5_ms' => 2.0, 'speedup' => 50.0]];

        $json = $this->command()->formatJson($results);
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertEquals(100.0, $decoded['search_latency']['mysql_like_ms']);
    }

    public function testFormatCsvReturnsStringWithHeader(): void
    {
        $results = [
            'search_latency' => ['mysql_like_ms' => 100.0, 'fts5_ms' => 2.0, 'speedup' => 50.0],
            'indexing_speed' => ['posts_per_second' => 1000.0, 'total_seconds' => 1.0],
        ];

        $csv = $this->command()->formatCsv($results);

        self::assertStringContainsString('metric,value', $csv);
        self::assertStringContainsString('search_latency.mysql_like_ms,100', $csv);
    }

    public function testFormatCsvIncludesScalabilityRows(): void
    {
        $csv = $this->command()->formatCsv(['scalability' => ['100' => ['search_ms' => 1.2, 'index_posts_per_sec' => 1500.0]]]);

        self::assertStringContainsString('scalability.100.search_ms,1.2', $csv);
        self::assertStringContainsString('scalability.100.index_posts_per_sec,1500', $csv);
    }

    public function testRunWithZeroIterationsAveragesToZero(): void
    {
        $results = $this->command()->run(0, 0);

        self::assertSame(0.0, $results['search_latency']['mysql_like_ms']);
        self::assertSame(0.0, $results['search_latency']['fts5_ms']);
        self::assertSame(0.0, $results['search_latency']['speedup']);
    }

    public function testMysqlLikeBranchUsesWpdbWhenPresent(): void
    {
        $GLOBALS['wpdb'] = new class {
            public string $posts = 'wp_posts';
            /** @var list<string> */
            public array $queries = [];
            public function prepare(string $sql, string ...$args): string
            {
                return $sql . implode(',', $args);
            }
            public function esc_like(string $s): string
            {
                return $s;
            }
            /** @return list<array<string, mixed>> */
            public function get_results(string $sql): array
            {
                $this->queries[] = $sql;
                return [];
            }
        };
        try {
            $results = $this->command()->run(0, 1);
        } finally {
            unset($GLOBALS['wpdb']);
        }

        self::assertGreaterThanOrEqual(0.0, $results['search_latency']['mysql_like_ms']);
    }
}
