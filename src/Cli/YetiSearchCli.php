<?php
declare(strict_types=1);

namespace WpYetiSearch\Cli;

use Psr\Log\LoggerInterface;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Features\CacheBridge;
use WpYetiSearch\Features\SemanticBridge;
use WpYetiSearch\Index\BulkIndexer;
use WpYetiSearch\Index\DocumentMapper;
use WpYetiSearch\Search\ResultNormalizer;
use WpYetiSearch\Search\SearchService;
use WpYetiSearch\Storage\StorageManager;
use YetiSearch\YetiSearch;

/**
 * WP-CLI commands for WP YetiSearch (spec §8).
 *
 * Commands follow WP-CLI conventions: plain English output, no translation functions.
 */
final class YetiSearchCli {

	private BulkIndexer $indexer;
	private HealthChecker $healthChecker;
	private SearchService $searchService;
	private SemanticBridge $semantic;
	private CacheBridge $cacheBridge;

	public function __construct(
		private Config $config,
		private ?YetiSearch $yeti,
		private StorageManager $storage,
		private LoggerInterface $logger,
		?BulkIndexer $indexer = null,
		?HealthChecker $healthChecker = null,
		?SearchService $searchService = null,
		?SemanticBridge $semantic = null,
		?CacheBridge $cacheBridge = null
	) {
		$this->indexer       = $indexer ?? new BulkIndexer( $yeti, new DocumentMapper( $config ), $config, $logger );
		$this->healthChecker = $healthChecker ?? new HealthChecker();
		$this->searchService = $searchService ?? new SearchService( $yeti, $config, new ResultNormalizer( $config ) );
		$this->semantic      = $semantic ?? new SemanticBridge( $yeti, $config, $logger );
		$this->cacheBridge   = $cacheBridge ?? new CacheBridge( $yeti, $config, $this->searchService, $logger );
	}

	public function register(): void {
		if ( $this->yeti === null ) {
			return;
		}
		\WP_CLI::add_command( 'yetisearch', $this );
	}

	/** The progress-bar stub type is unknown to static analysis; guard the calls. */
	private static function tick( mixed $bar, int $n ): void {
		if ( is_object( $bar ) && method_exists( $bar, 'tick' ) ) {
			$bar->tick( $n );
		}
	}

	/** The progress-bar stub type is unknown to static analysis; guard the calls. */
	private static function finish( mixed $bar ): void {
		if ( is_object( $bar ) && method_exists( $bar, 'finish' ) ) {
			$bar->finish();
		}
	}

	/**
	 * Bulk index posts with a progress bar.
	 *
	 * @synopsis [--batch=<count>] [--post-type=<types>] [--force]
	 * @alias reindex
	 */
	public function index( array $args, array $assoc_args ): void {
		$perPage   = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', 100 );
		$perPage   = max( 1, min( 500, $perPage ) );
		$postTypes = array_values( array_filter( array_map( 'trim', explode( ',', (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'post-type', '' ) ) ) ) );
		$force     = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		$page           = 1;
		$totalProcessed = 0;
		$totalIndexed   = 0;

		$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing posts', 0 );

		do {
			$result          = $this->indexer->run( $page, $perPage, $postTypes, $force && $page === 1 );
			$totalProcessed += $result['processed'];
			$totalIndexed   += $result['indexed'];
			self::tick( $progress, $result['processed'] );

			if ( $result['error'] !== null ) {
				self::finish( $progress );
				\WP_CLI::error( (string) $result['error'] );
				return;
			}

			++$page;
		} while ( ! $result['finished'] );

		self::finish( $progress );
		\WP_CLI::success( sprintf( 'Indexed %d documents (%d processed).', $totalIndexed, $totalProcessed ) );
	}

	/**
	 * Run health checks and refresh the cache.
	 *
	 * @synopsis
	 */
	public function check( array $args, array $assoc_args ): void {
		$record = $this->healthChecker->refresh( $this->storage->storageDir() );

		$rows = array();
		foreach ( $record['checks'] as $key => $check ) {
			$rows[] = array(
				'check'  => $key,
				'status' => $check['ok'] ? 'OK' : 'FAIL',
				'value'  => $check['value'],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'status', 'value' ) );

		if ( $record['ready'] ) {
			\WP_CLI::success( 'All checks passed.' );
		} else {
			\WP_CLI::warning( 'Some checks failed. See above for details.' );
		}
	}

	/**
	 * Show cached health status.
	 *
	 * @synopsis
	 */
	public function health( array $args, array $assoc_args ): void {
		$record = $this->healthChecker->cached();

		if ( $record === null ) {
			\WP_CLI::warning( 'No cached health record. Run `wp yetisearch check` first.' );
			return;
		}

		$rows = array();
		foreach ( $record['checks'] as $key => $check ) {
			$rows[] = array(
				'check'  => $key,
				'status' => $check['ok'] ? 'OK' : 'FAIL',
				'value'  => $check['value'],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'status', 'value' ) );

		if ( $record['ready'] ) {
			\WP_CLI::success( 'System is ready.' );
		} else {
			\WP_CLI::warning( 'System is not ready. Run `wp yetisearch check` to refresh.' );
		}
	}

	/**
	 * Show index statistics.
	 *
	 * @synopsis
	 */
	public function stats( array $args, array $assoc_args ): void {
		$stats = $this->searchService->indexStats();

		if ( $stats === array() ) {
			\WP_CLI::warning( 'Index is not available.' );
			return;
		}

		$rows   = array();
		$rows[] = array( 'documents', (string) ( $stats['documents'] ?? 'n/a' ) );

		foreach ( $stats['stats'] ?? array() as $key => $value ) {
			$rows[] = array( (string) $key, (string) $value );
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'value' ) );
	}

	/**
	 * Embed pending documents until none remain.
	 *
	 * @synopsis [--batch=<count>]
	 */
	public function embed( array $args, array $assoc_args ): void {
		$batch = max( 1, min( 500, (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', $this->config->int( 'semantic_batch_size' ) ) ) );
		do {
			$result = $this->semantic->embedBatch( $batch );
			if ( ( $result['error'] ?? null ) === 'disabled' ) {
				\WP_CLI::warning( 'Semantic search is disabled.' );
				return;
			}
			if ( $result['error'] !== null ) {
				\WP_CLI::error( (string) $result['error'] );
				return;
			}
		} while ( $result['pending'] > 0 );
		\WP_CLI::success( sprintf( 'Embedded %d documents.', $result['total'] ) );
	}

	/**
	 * Calibrate the semantic noise gate.
	 *
	 * @synopsis
	 */
	public function calibrate( array $args, array $assoc_args ): void {
		$result = $this->semantic->calibrate();
		if ( ! $result['ok'] ) {
			\WP_CLI::warning( 'Calibration unavailable: ' . (string) ( $result['error'] ?? 'unknown' ) );
			return;
		}
		\WP_CLI::success( 'Calibration complete.' );
	}

	/**
	 * Empty the index. Requires --yes.
	 *
	 * @synopsis [--yes]
	 */
	public function clear( array $args, array $assoc_args ): void {
		if ( ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false ) ) {
			\WP_CLI::warning( 'Refusing to clear without --yes.' );
			return;
		}
		if ( $this->yeti === null ) {
			\WP_CLI::warning( 'Index is not available.' );
			return;
		}
		try {
			$this->yeti->clear( Config::INDEX );
			$this->yeti->clearCache();
			\WP_CLI::success( 'Index cleared.' );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Test a search query.
	 *
	 * @synopsis <term> [--limit=<count>] [--no-fuzzy]
	 */
	public function query( array $args, array $assoc_args ): void {
		$term = trim( (string) ( $args[0] ?? '' ) );
		if ( $term === '' ) {
			\WP_CLI::error( 'Missing search term.' );
			return;
		}
		$limit = max( 1, min( 20, (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 10 ) ) );
		try {
			$query = $this->searchService->frontQuery( $term, $this->config->strings( 'indexed_post_types' ), $limit, 1 );
			if ( (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'no-fuzzy', false ) ) {
				$query->fuzzy( false );
			}
			$result = $this->searchService->run( $query );
			$rows   = array_map(
				static fn ( array $item ): array => array(
					'id'    => $item['post_id'],
					'score' => $item['score'],
					'title' => $item['title'],
					'url'   => $item['url'],
				),
				$result['items']
			);
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'score', 'title', 'url' ) );
			if ( is_string( $result['suggestion'] ) && $result['suggestion'] !== '' ) {
				\WP_CLI::line( 'Did you mean: ' . $result['suggestion'] );
			}
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Inspect the query cache.
	 *
	 * @synopsis <clear|warmup>
	 */
	public function cache( array $args, array $assoc_args ): void {
		$sub = (string) ( $args[0] ?? '' );
		if ( $sub === 'clear' ) {
			\WP_CLI::success( $this->cacheBridge->clear() ? 'Cache cleared.' : 'Cache is not available.' );
			return;
		}
		if ( $sub === 'warmup' ) {
			$result = $this->cacheBridge->warmup();
			\WP_CLI::success( sprintf( 'Warmed %d queries (%d failed).', $result['warmed'], $result['failed'] ) );
			return;
		}
		\WP_CLI::error( 'Usage: wp yetisearch cache <clear|warmup>' );
	}
}
