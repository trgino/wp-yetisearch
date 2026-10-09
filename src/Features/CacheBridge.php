<?php
declare(strict_types=1);

namespace WpYetiSearch\Features;

use Psr\Log\LoggerInterface;
use WpYetiSearch\Core\Config;
use WpYetiSearch\Search\SearchService;
use YetiSearch\YetiSearch;

/**
 * Query cache maintenance. Warm-up replays the exact front-end query, because the
 * library's warmUpCache() goes through YetiSearch::search() and uses another cache key.
 */
final class CacheBridge {

	public function __construct(
		private ?YetiSearch $yeti,
		private Config $config,
		private SearchService $search,
		private LoggerInterface $logger
	) {
	}

	public function clear(): bool {
		if ( $this->yeti === null ) {
			return false;
		}
		try {
			$this->yeti->clearCache();
			return true;
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'Clearing the cache failed', array( 'exception' => $e ) );
			return false;
		}
	}

	/** @return array{warmed: int, failed: int} */
	public function warmup(): array {
		$result = array(
			'warmed' => 0,
			'failed' => 0,
		);
		if ( ! $this->search->isAvailable() ) {
			return $result;
		}
		$perPage = max( 1, (int) get_option( 'posts_per_page', 10 ) );
		$types   = $this->config->strings( 'indexed_post_types' );

		foreach ( $this->config->strings( 'warmup_queries' ) as $term ) {
			try {
				$this->search->run( $this->search->frontQuery( $term, $types, $perPage, 1 ) );
				++$result['warmed'];
			} catch ( \Throwable $e ) {
				$this->logger->warning(
					'Cache warm-up query failed',
					array(
						'term'      => $term,
						'exception' => $e,
					)
				);
				++$result['failed'];
			}
		}
		return $result;
	}

	public function stats(): array {
		if ( $this->yeti === null ) {
			return array();
		}
		try {
			return $this->yeti->getCacheStats();
		} catch ( \Throwable ) {
			return array();
		}
	}
}
