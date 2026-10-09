<?php
declare(strict_types=1);

namespace WpYetiSearch\Cli;

use Psr\Log\LoggerInterface;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\BulkIndexer;
use WpYetiSearch\Search\SearchService;

/**
 * WP-CLI benchmark command: compares MySQL LIKE vs FTS5 search latency,
 * indexing throughput, memory usage, and scalability across post counts.
 *
 * WARNING: run() force-clears the live index. Staging / throwaway environments only.
 *
 * Can also be run standalone via `php bin/benchmark.php`.
 */
final class BenchmarkCommand {

	public const NAME = 'benchmark';

	/** @var list<int> */
	private const SCALES = array( 100, 1000, 10000 );

	/** @var list<string> */
	private const QUERIES = array( 'wordpress', 'search', 'plugin', 'performance', 'database' );

	public function __construct(
		private BulkIndexer $indexer,
		private SearchService $search,
		private Config $config,
		private LoggerInterface $logger
	) {
	}

	/**
	 * Run the full benchmark suite.
	 *
	 * @return array{search_latency: array{mysql_like_ms: float, fts5_ms: float, speedup: float}, indexing_speed: array{posts_per_second: float, total_seconds: float}, memory_usage: array{indexing_peak_mb: float, search_peak_mb: float}, scalability: array<int, array{search_ms: float, index_posts_per_sec: float}>}
	 */
	public function run( int $postCount, int $iterations ): array {
		return array(
			'search_latency' => $this->benchmarkSearchLatency( $iterations ),
			'indexing_speed' => $this->benchmarkIndexingSpeed( $postCount ),
			'memory_usage'   => $this->benchmarkMemoryUsage( $postCount, $iterations ),
			'scalability'    => $this->benchmarkScalability( $iterations ),
		);
	}

	/**
	 * @return array{mysql_like_ms: float, fts5_ms: float, speedup: float}
	 */
	private function benchmarkSearchLatency( int $iterations ): array {
		$mysqlTimes = array();
		$fts5Times  = array();

		foreach ( self::QUERIES as $query ) {
			for ( $i = 0; $i < $iterations; $i++ ) {
				$mysqlTimes[] = $this->timeMySqlLike( $query );
				$fts5Times[]  = $this->timeFts5( $query );
			}
		}

		$mysqlAvg = $this->average( $mysqlTimes );
		$fts5Avg  = $this->average( $fts5Times );

		return array(
			'mysql_like_ms' => round( $mysqlAvg, 3 ),
			'fts5_ms'       => round( $fts5Avg, 3 ),
			'speedup'       => $fts5Avg > 0 ? round( $mysqlAvg / $fts5Avg, 2 ) : 0.0,
		);
	}

	/**
	 * @return array{posts_per_second: float, total_seconds: float}
	 */
	private function benchmarkIndexingSpeed( int $postCount ): array {
		if ( $postCount <= 0 ) {
			return array(
				'posts_per_second' => 0.0,
				'total_seconds'    => 0.0,
			);
		}

		$start = microtime( true );

		// Index in batches of 100
		$batchSize = 100;
		$pages     = (int) ceil( $postCount / $batchSize );
		for ( $page = 1; $page <= $pages; $page++ ) {
			$this->indexer->run( $page, $batchSize, array(), true );
		}

		$elapsed = microtime( true ) - $start;

		return array(
			'posts_per_second' => $elapsed > 0 ? round( $postCount / $elapsed, 2 ) : 0.0,
			'total_seconds'    => round( $elapsed, 3 ),
		);
	}

	/**
	 * @return array{indexing_peak_mb: float, search_peak_mb: float}
	 */
	private function benchmarkMemoryUsage( int $postCount, int $iterations ): array {
		// Measure indexing peak
		$this->benchmarkIndexingSpeed( $postCount );
		$indexingPeak = memory_get_peak_usage( true );

		// Measure search peak
		$this->benchmarkSearchLatency( $iterations );
		$searchPeak = memory_get_peak_usage( true );

		return array(
			'indexing_peak_mb' => round( $indexingPeak / 1048576, 2 ),
			'search_peak_mb'   => round( $searchPeak / 1048576, 2 ),
		);
	}

	/**
	 * @return array<int, array{search_ms: float, index_posts_per_sec: float}>
	 */
	private function benchmarkScalability( int $iterations ): array {
		$results = array();

		foreach ( self::SCALES as $scale ) {
			$searchLatency = $this->benchmarkSearchLatency( $iterations );
			$indexSpeed    = $this->benchmarkIndexingSpeed( $scale );

			$results[ (string) $scale ] = array(
				'search_ms'           => $searchLatency['fts5_ms'],
				'index_posts_per_sec' => $indexSpeed['posts_per_second'],
			);
		}

		return $results;
	}

	private function timeMySqlLike( string $query ): float {
		$start = microtime( true );

		// Simulate MySQL LIKE search: full table scan with wildcard
		global $wpdb;
		if ( isset( $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- benchmark intentionally measures the raw LIKE query.
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s OR post_content LIKE %s LIMIT 100",
					'%' . $wpdb->esc_like( $query ) . '%',
					'%' . $wpdb->esc_like( $query ) . '%'
				)
			);
		}

		return ( microtime( true ) - $start ) * 1000;
	}

	private function timeFts5( string $query ): float {
		$start = microtime( true );

		try {
			$searchQuery = $this->search->frontQuery(
				$query,
				$this->config->strings( 'indexed_post_types' ),
				100,
				1
			);
			$this->search->run( $searchQuery );
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'FTS5 benchmark query failed',
				array(
					'query'     => $query,
					'exception' => $e,
				)
			);
		}

		return ( microtime( true ) - $start ) * 1000;
	}

	/**
	 * @param list<float> $times
	 */
	private function average( array $times ): float {
		if ( $times === array() ) {
			return 0.0;
		}
		return array_sum( $times ) / count( $times );
	}

	/**
	 * @param array<string, mixed> $results
	 */
	public function formatTable( array $results ): string {
		$lines   = array();
		$lines[] = '╔══════════════════════════════════════════════════════════════╗';
		$lines[] = '║              WP YetiSearch Benchmark Results               ║';
		$lines[] = '╚══════════════════════════════════════════════════════════════╝';
		$lines[] = '';

		// Search Latency
		$lines[] = 'Search Latency';
		$lines[] = str_repeat( '─', 50 );
		$lines[] = sprintf( '  MySQL LIKE:  %10.3f ms', $results['search_latency']['mysql_like_ms'] );
		$lines[] = sprintf( '  FTS5:        %10.3f ms', $results['search_latency']['fts5_ms'] );
		$lines[] = sprintf( '  Speedup:     %10.2fx', $results['search_latency']['speedup'] );
		$lines[] = '';

		// Indexing Speed
		$lines[] = 'Indexing Speed';
		$lines[] = str_repeat( '─', 50 );
		$lines[] = sprintf( '  Posts/sec:   %10.2f', $results['indexing_speed']['posts_per_second'] );
		$lines[] = sprintf( '  Total time:  %10.3f s', $results['indexing_speed']['total_seconds'] );
		$lines[] = '';

		// Memory Usage
		$lines[] = 'Memory Usage';
		$lines[] = str_repeat( '─', 50 );
		$lines[] = sprintf( '  Indexing peak: %8.2f MB', $results['memory_usage']['indexing_peak_mb'] );
		$lines[] = sprintf( '  Search peak:   %8.2f MB', $results['memory_usage']['search_peak_mb'] );
		$lines[] = '';

		// Scalability
		$lines[] = 'Scalability';
		$lines[] = str_repeat( '─', 50 );
		$lines[] = sprintf( '  %10s  %12s  %18s', 'Posts', 'Search (ms)', 'Index (posts/sec)' );
		foreach ( $results['scalability'] as $scale => $data ) {
			$lines[] = sprintf( '  %10s  %12.3f  %18.2f', $scale, $data['search_ms'], $data['index_posts_per_sec'] );
		}
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * @param array<string, mixed> $results
	 */
	public function formatJson( array $results ): string {
		return (string) wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param array<string, mixed> $results
	 */
	public function formatCsv( array $results ): string {
		$latency = $results['search_latency'] ?? array();
		$speed   = $results['indexing_speed'] ?? array();
		$memory  = $results['memory_usage'] ?? array();
		$lines   = array( 'metric,value' );
		$lines[] = 'search_latency.mysql_like_ms,' . ( $latency['mysql_like_ms'] ?? 0 );
		$lines[] = 'search_latency.fts5_ms,' . ( $latency['fts5_ms'] ?? 0 );
		$lines[] = 'search_latency.speedup,' . ( $latency['speedup'] ?? 0 );
		$lines[] = 'indexing_speed.posts_per_second,' . ( $speed['posts_per_second'] ?? 0 );
		$lines[] = 'indexing_speed.total_seconds,' . ( $speed['total_seconds'] ?? 0 );
		$lines[] = 'memory_usage.indexing_peak_mb,' . ( $memory['indexing_peak_mb'] ?? 0 );
		$lines[] = 'memory_usage.search_peak_mb,' . ( $memory['search_peak_mb'] ?? 0 );

		foreach ( $results['scalability'] ?? array() as $scale => $data ) {
			$lines[] = 'scalability.' . $scale . '.search_ms,' . $data['search_ms'];
			$lines[] = 'scalability.' . $scale . '.index_posts_per_sec,' . $data['index_posts_per_sec'];
		}

		return implode( "\n", $lines );
	}
}
